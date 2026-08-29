<?php

namespace App\Services\Wallet;

use App\Enums\WalletType;
use App\Models\CommissionEntry;
use App\Models\Order;
use App\Models\TradespersonProfile;
use App\Models\Wallet;
use App\Models\WalletTopup;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Double-entry wallet ledger.
 *
 * Money model (all amounts integer YER):
 *   - tradesperson wallet buckets:
 *       available_balance  -> prepaid commission float + platform payouts (withdrawable/spendable)
 *       pending_balance    -> escrow held for prepaid (non-COD) orders in progress
 *       commission_due     -> outstanding commission the tradesperson owes the platform
 *   - system wallets:
 *       platform_escrow    -> mirror of all tradesperson floats + in-flight escrow the platform custodies
 *       platform_revenue   -> recognised commission income
 *
 * Every posting is atomic, row-locked, and writes >= 2 wallet_transactions rows
 * sharing a group_uuid. Denormalised balance columns are updated inside the same txn.
 */
class WalletService
{
    public function walletFor(TradespersonProfile $profile): Wallet
    {
        return Wallet::firstOrCreate(
            ['owner_type' => $profile->getMorphClass(), 'owner_id' => $profile->id, 'type' => WalletType::Tradesperson->value],
            ['currency' => config('platform.currency')],
        );
    }

    public function systemWallet(WalletType $type): Wallet
    {
        if (! $type->isSystem()) {
            throw new \InvalidArgumentException('Not a system wallet type.');
        }

        return Wallet::firstOrCreate(
            ['owner_type' => null, 'owner_id' => null, 'type' => $type->value],
            ['currency' => config('platform.currency')],
        );
    }

    /**
     * Post a balanced set of legs. Debits and credits must net to zero
     * (bucket-agnostic — a transfer between buckets of one wallet is still balanced).
     *
     * @param  LedgerLeg[]  $legs
     */
    public function post(array $legs, ?string $groupUuid = null): string
    {
        $net = array_sum(array_map(fn (LedgerLeg $l) => $l->signedDelta(), $legs));
        if ($net !== 0) {
            throw new RuntimeException("Unbalanced ledger posting (net {$net}).");
        }

        $groupUuid ??= (string) Str::uuid();

        DB::transaction(function () use ($legs, $groupUuid) {
            // Lock every wallet row involved, in a stable order, to avoid deadlocks.
            $walletIds = collect($legs)->pluck('wallet.id')->unique()->sort()->values();
            $wallets = Wallet::whereIn('id', $walletIds)->lockForUpdate()->get()->keyBy('id');

            foreach ($legs as $leg) {
                $wallet = $wallets[$leg->wallet->id];
                if ($wallet->is_frozen && $leg->type !== 'adjustment') {
                    throw new RuntimeException("Wallet #{$wallet->id} is frozen.");
                }

                $column = $wallet->bucketColumn($leg->bucket);
                $wallet->{$column} += $leg->signedDelta();

                if (in_array($leg->bucket, ['available', 'pending'], true) && $wallet->{$column} < 0) {
                    throw new RuntimeException("Insufficient {$leg->bucket} balance on wallet #{$wallet->id}.");
                }

                // Lifetime counters.
                if ($leg->type === 'order_earning' && $leg->direction === 'credit') {
                    $wallet->lifetime_earnings += $leg->amount;
                }
                if ($leg->type === 'commission_charge' && $leg->direction === 'debit' && $leg->bucket === 'available') {
                    $wallet->lifetime_commission_paid += $leg->amount;
                }

                $wallet->save();

                WalletTransaction::create([
                    'group_uuid' => $groupUuid,
                    'wallet_id' => $wallet->id,
                    'direction' => $leg->direction,
                    'bucket' => $leg->bucket,
                    'amount' => $leg->amount,
                    'balance_after' => $wallet->{$column},
                    'type' => $leg->type,
                    'reference_type' => $leg->reference?->getMorphClass(),
                    'reference_id' => $leg->reference?->getKey(),
                    'description' => $leg->description,
                    'created_by' => auth()->id(),
                    'meta' => $leg->meta,
                ]);
            }
        });

        return $groupUuid;
    }

    /** Confirmed top-up: tradesperson float increases; platform custodies the cash. */
    public function confirmTopup(WalletTopup $topup): string
    {
        $wallet = $topup->wallet;
        $escrow = $this->systemWallet(WalletType::PlatformEscrow);

        return $this->post([
            new LedgerLeg($wallet, 'credit', 'available', $topup->amount, 'topup',
                "Top-up {$topup->reference_code}", $topup, ['method' => $topup->method]),
            new LedgerLeg($escrow, 'credit', 'available', $topup->amount, 'topup',
                "Custody of top-up #{$topup->id}", $topup),
        ]);
    }

    /**
     * Order completed under COD:
     *   - record the tradesperson's gross earning (cash collected directly from the customer)
     *   - accrue the platform commission as commission_due
     *   - immediately settle as much commission as the prepaid float covers
     */
    public function settleCompletedOrder(Order $order): CommissionEntry
    {
        $profile = $order->tradesperson;
        if (! $profile) {
            throw new RuntimeException("Order {$order->reference} has no assigned tradesperson.");
        }

        $wallet = $this->walletFor($profile);
        $revenue = $this->systemWallet(WalletType::PlatformRevenue);
        $escrow = $this->systemWallet(WalletType::PlatformEscrow);

        $subtotal = $order->subtotal;
        $commission = $order->commission_amount ?: (int) round($subtotal * $order->commission_percent / 100);

        return DB::transaction(function () use ($order, $profile, $wallet, $revenue, $escrow, $subtotal, $commission) {
            $entry = CommissionEntry::updateOrCreate(
                ['order_id' => $order->id],
                [
                    'tradesperson_profile_id' => $profile->id,
                    'order_subtotal' => $subtotal,
                    'commission_percent' => $order->commission_percent,
                    'commission_amount' => $commission,
                    'settlement' => 'prepaid_balance',
                    'status' => 'accrued',
                ],
            );

            // Accrual: commission_due up on the tradesperson; receivable recognised as revenue-pending.
            $this->post([
                new LedgerLeg($wallet, 'credit', 'commission_due', $commission, 'commission_accrual',
                    "Commission accrued for {$order->reference}", $order),
                new LedgerLeg($revenue, 'credit', 'pending', $commission, 'commission_accrual',
                    "Receivable for {$order->reference}", $order),
            ]);

            // Book the gross earning as a lifetime stat (cash is off-ledger with COD).
            WalletTransaction::create([
                'group_uuid' => (string) Str::uuid(),
                'wallet_id' => $wallet->id,
                'direction' => 'credit',
                'bucket' => 'available',
                'amount' => 0,
                'balance_after' => $wallet->fresh()->available_balance,
                'type' => 'order_earning',
                'reference_type' => $order->getMorphClass(),
                'reference_id' => $order->id,
                'description' => "COD earning recorded for {$order->reference} ({$subtotal} YER collected in cash)",
                'meta' => ['gross' => $subtotal, 'off_ledger_cash' => true],
            ]);
            $wallet->increment('lifetime_earnings', $subtotal);

            // Immediate settlement from prepaid float.
            $wallet->refresh();
            $settleable = min($commission, max(0, $wallet->available_balance));
            if ($settleable > 0) {
                $this->post([
                    new LedgerLeg($wallet, 'debit', 'available', $settleable, 'commission_charge',
                        "Commission settled for {$order->reference}", $order),
                    new LedgerLeg($wallet, 'debit', 'commission_due', $settleable, 'commission_charge',
                        "Clear commission_due for {$order->reference}", $order),
                    new LedgerLeg($escrow, 'debit', 'available', $settleable, 'commission_charge',
                        "Release custody for {$order->reference}", $order),
                    new LedgerLeg($revenue, 'debit', 'pending', $settleable, 'commission_charge',
                        "Recognise receivable for {$order->reference}", $order),
                    new LedgerLeg($revenue, 'credit', 'available', $settleable, 'commission_charge',
                        "Commission income {$order->reference}", $order),
                ]);
            }

            $entry->update([
                'status' => $settleable >= $commission ? 'settled' : 'accrued',
                'settled_at' => $settleable >= $commission ? now() : null,
            ]);

            return $entry;
        });
    }

    public function adjust(Wallet $wallet, string $bucket, int $signedAmount, string $reason): string
    {
        $direction = $signedAmount >= 0 ? 'credit' : 'debit';
        $counter = $this->systemWallet(WalletType::PlatformRevenue);

        return $this->post([
            new LedgerLeg($wallet, $direction, $bucket, abs($signedAmount), 'adjustment', $reason),
            new LedgerLeg($counter, $direction === 'credit' ? 'debit' : 'credit', 'available', abs($signedAmount), 'adjustment', "Counter-leg: {$reason}"),
        ]);
    }
}
