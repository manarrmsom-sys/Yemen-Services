<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\WalletType;
use App\Models\CommissionEntry;
use App\Models\Governorate;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\TradespersonProfile;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTopup;
use App\Models\WalletTransaction;
use App\Services\Wallet\LedgerLeg;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Double-entry invariants for the wallet ledger, with platform_clearing as the
 * counter-account for money that moves outside the platform (top-ups, COD cash).
 */
class WalletLedgerTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(WalletService::class);
    }

    private function tradesperson(): TradespersonProfile
    {
        $cat = ServiceCategory::firstOrCreate(['slug' => 'plumbing'], ['name_ar' => 'سباكة', 'name_en' => 'Plumbing']);
        $gov = Governorate::firstOrCreate(['code' => 'sanaa'], ['name_ar' => 'صنعاء', 'name_en' => 'Sanaa', 'dispatch_multiplier' => 1.0]);
        $user = User::factory()->tradesperson()->create();

        return TradespersonProfile::create([
            'user_id' => $user->id, 'primary_category_id' => $cat->id, 'base_governorate_id' => $gov->id,
            'display_name' => 'فني', 'kyc_status' => 'verified', 'is_accepting_jobs' => true,
        ]);
    }

    private function completedOrder(TradespersonProfile $pro, int $subtotal = 8000, int $commission = 960): Order
    {
        $cat = ServiceCategory::where('slug', 'plumbing')->first();
        $service = Service::firstOrCreate(['slug' => 'leak'], [
            'service_category_id' => $cat->id, 'name_ar' => 'تسريب', 'name_en' => 'Leak',
            'pricing_model' => 'fixed', 'base_price' => 8000, 'callout_fee' => 2000, 'min_duration_minutes' => 60,
        ]);

        return Order::create([
            'service_id' => $service->id, 'service_category_id' => $cat->id,
            'governorate_id' => Governorate::where('code', 'sanaa')->value('id'),
            'tradesperson_profile_id' => $pro->id,
            'status' => OrderStatus::Completed, 'urgency' => 'today', 'payment_method' => 'cod',
            'subtotal' => $subtotal, 'total_estimate' => $subtotal + 2000,
            'commission_percent' => 12, 'commission_amount' => $commission,
            'contact_name' => 'عميل', 'contact_phone' => '+967733111222',
            'price_breakdown' => ['lines' => []],
        ]);
    }

    private function topup(TradespersonProfile $pro, int $amount): WalletTopup
    {
        $wallet = $this->ledger->walletFor($pro);

        return WalletTopup::create([
            'wallet_id' => $wallet->id, 'tradesperson_profile_id' => $pro->id, 'amount' => $amount,
            'method' => 'bank_transfer', 'reference_code' => 'TX-'.$amount, 'status' => 'confirmed',
        ]);
    }

    private function clearing(): Wallet
    {
        return $this->ledger->systemWallet(WalletType::PlatformClearing)->fresh();
    }

    private function revenue(): Wallet
    {
        return $this->ledger->systemWallet(WalletType::PlatformRevenue)->fresh();
    }

    /** Signed sum of every leg in the ledger, and of every individual posting group. */
    private function assertLedgerBalanced(): void
    {
        $signed = "SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END)";

        $this->assertSame(0, (int) WalletTransaction::query()->selectRaw("$signed as net")->value('net'),
            'The whole ledger must net to zero.');

        $unbalanced = WalletTransaction::query()
            ->select('group_uuid')->selectRaw("$signed as net")
            ->groupBy('group_uuid')->havingRaw("$signed <> 0")->get();
        $this->assertCount(0, $unbalanced, 'Every group_uuid must net to zero.');

        // Denormalised balance columns must agree with the journal.
        $columns = (int) Wallet::query()->selectRaw('SUM(available_balance + pending_balance + commission_due) as t')->value('t');
        $this->assertSame(0, $columns, 'Wallet balance columns must net to zero across all wallets.');

        foreach (Wallet::all() as $wallet) {
            foreach (['available' => 'available_balance', 'pending' => 'pending_balance', 'commission_due' => 'commission_due'] as $bucket => $column) {
                $journal = (int) WalletTransaction::where('wallet_id', $wallet->id)->where('bucket', $bucket)
                    ->selectRaw("$signed as net")->value('net');
                $this->assertSame($journal, $wallet->{$column}, "Wallet #{$wallet->id} {$bucket} drifted from its journal.");
            }
        }
    }

    public function test_platform_clearing_is_a_system_wallet_and_is_created_once(): void
    {
        $this->assertTrue(WalletType::PlatformClearing->isSystem());

        $a = $this->ledger->systemWallet(WalletType::PlatformClearing);
        $b = $this->ledger->systemWallet(WalletType::PlatformClearing);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Wallet::where('type', 'platform_clearing')->count());
    }

    public function test_tradesperson_type_is_not_a_system_wallet(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ledger->systemWallet(WalletType::Tradesperson);
    }

    public function test_topup_credits_the_float_against_clearing(): void
    {
        $pro = $this->tradesperson();
        $group = $this->ledger->confirmTopup($this->topup($pro, 5000));

        $wallet = $this->ledger->walletFor($pro)->fresh();
        $this->assertSame(5000, $wallet->available_balance);
        $this->assertSame(-5000, $this->clearing()->available_balance, 'Clearing goes negative: money came from outside.');
        $this->assertSame(0, $this->revenue()->available_balance, 'A top-up is not income.');

        $legs = WalletTransaction::where('group_uuid', $group)->get();
        $this->assertCount(2, $legs);
        $this->assertEqualsCanonicalizing(['credit', 'debit'], $legs->pluck('direction')->all());
        $this->assertTrue($legs->every(fn ($l) => $l->type === 'topup' && $l->amount === 5000));

        $this->assertLedgerBalanced();
    }

    public function test_topup_no_longer_touches_escrow(): void
    {
        $pro = $this->tradesperson();
        $this->ledger->confirmTopup($this->topup($pro, 5000));

        $this->assertSame(0, Wallet::where('type', 'platform_escrow')->count());
    }

    public function test_cod_commission_is_fully_settled_from_a_sufficient_float(): void
    {
        $pro = $this->tradesperson();
        $this->ledger->confirmTopup($this->topup($pro, 5000));

        $entry = $this->ledger->settleCompletedOrder($this->completedOrder($pro, 8000, 960));

        $wallet = $this->ledger->walletFor($pro)->fresh();
        $this->assertSame(4040, $wallet->available_balance);
        $this->assertSame(0, $wallet->commission_due);
        $this->assertSame(8000, $wallet->lifetime_earnings);
        $this->assertSame(960, $wallet->lifetime_commission_paid);

        $this->assertSame(960, $this->revenue()->available_balance);
        // Clearing ends at minus the cash received externally (the top-up); the
        // commission receivable it carried was opened and closed.
        $this->assertSame(-5000, $this->clearing()->available_balance);

        $this->assertSame('settled', $entry->status);
        $this->assertNotNull($entry->settled_at);
        $this->assertSame(960, $entry->commission_amount);

        $this->assertLedgerBalanced();
    }

    public function test_cod_commission_is_partially_settled_when_the_float_is_short(): void
    {
        $pro = $this->tradesperson();
        $this->ledger->confirmTopup($this->topup($pro, 400));

        $entry = $this->ledger->settleCompletedOrder($this->completedOrder($pro, 8000, 960));

        $wallet = $this->ledger->walletFor($pro)->fresh();
        $this->assertSame(0, $wallet->available_balance, 'The float can never go negative.');
        $this->assertSame(560, $wallet->commission_due);
        $this->assertSame(400, $wallet->lifetime_commission_paid);
        $this->assertSame(400, $this->revenue()->available_balance, 'Only collected commission is income.');
        $this->assertSame(-400 - 560, $this->clearing()->available_balance, 'Top-up cash plus the open receivable.');

        $this->assertSame('accrued', $entry->status);
        $this->assertNull($entry->settled_at);

        $this->assertLedgerBalanced();
    }

    public function test_cod_commission_stays_due_when_there_is_no_float(): void
    {
        $pro = $this->tradesperson();

        $entry = $this->ledger->settleCompletedOrder($this->completedOrder($pro, 8000, 960));

        $wallet = $this->ledger->walletFor($pro)->fresh();
        $this->assertSame(0, $wallet->available_balance);
        $this->assertSame(960, $wallet->commission_due);
        $this->assertSame(0, $this->revenue()->available_balance);
        $this->assertSame(-960, $this->clearing()->available_balance);
        $this->assertSame('accrued', $entry->status);
        $this->assertSame(0, WalletTransaction::where('type', 'commission_charge')->count());

        $this->assertLedgerBalanced();
    }

    public function test_commission_is_derived_from_the_percent_when_no_amount_is_stored(): void
    {
        $pro = $this->tradesperson();
        $this->ledger->confirmTopup($this->topup($pro, 5000));

        $entry = $this->ledger->settleCompletedOrder($this->completedOrder($pro, 10000, 0));

        $this->assertSame(1200, $entry->commission_amount);
        $this->assertSame(1200, $this->revenue()->available_balance);
        $this->assertLedgerBalanced();
    }

    public function test_several_orders_and_topups_keep_the_ledger_balanced(): void
    {
        $a = $this->tradesperson();
        $b = $this->tradesperson();

        $this->ledger->confirmTopup($this->topup($a, 1000));
        $this->ledger->settleCompletedOrder($this->completedOrder($a, 8000, 960));   // fully settled
        $this->ledger->settleCompletedOrder($this->completedOrder($a, 8000, 960));   // 40 settled, 920 due
        $this->ledger->settleCompletedOrder($this->completedOrder($b, 5000, 600));   // nothing settled
        $this->ledger->confirmTopup($this->topup($b, 2500));

        $walletA = $this->ledger->walletFor($a)->fresh();
        $walletB = $this->ledger->walletFor($b)->fresh();

        $this->assertSame([0, 920], [$walletA->available_balance, $walletA->commission_due]);
        $this->assertSame([2500, 600], [$walletB->available_balance, $walletB->commission_due]);
        $this->assertSame(1000, $this->revenue()->available_balance);
        $this->assertSame(3, CommissionEntry::count());

        $this->assertLedgerBalanced();
    }

    public function test_order_without_a_tradesperson_cannot_be_settled(): void
    {
        $pro = $this->tradesperson();
        $order = $this->completedOrder($pro);
        $order->update(['tradesperson_profile_id' => null]);

        $this->expectException(RuntimeException::class);
        $this->ledger->settleCompletedOrder($order->fresh());
    }

    public function test_unbalanced_posting_is_rejected_and_writes_nothing(): void
    {
        $wallet = $this->ledger->walletFor($this->tradesperson());

        try {
            $this->ledger->post([
                new LedgerLeg($wallet, 'credit', 'available', 500, 'adjustment'),
                new LedgerLeg($this->clearing(), 'debit', 'available', 499, 'adjustment'),
            ]);
            $this->fail('An unbalanced posting must throw.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Unbalanced', $e->getMessage());
        }

        $this->assertSame(0, WalletTransaction::count());
        $this->assertSame(0, $wallet->fresh()->available_balance);
    }

    public function test_only_clearing_may_go_negative(): void
    {
        $wallet = $this->ledger->walletFor($this->tradesperson());

        try {
            // Overdrawing a tradesperson float must fail and roll back both legs.
            $this->ledger->post([
                new LedgerLeg($wallet, 'debit', 'available', 100, 'withdrawal'),
                new LedgerLeg($this->clearing(), 'credit', 'available', 100, 'withdrawal'),
            ]);
            $this->fail('Overdrawing a tradesperson wallet must throw.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Insufficient available balance', $e->getMessage());
        }

        $this->assertSame(0, WalletTransaction::count(), 'A failed posting leaves no partial legs.');
        $this->assertSame(0, $this->clearing()->available_balance);
    }

    public function test_frozen_wallet_rejects_postings(): void
    {
        $pro = $this->tradesperson();
        $this->ledger->walletFor($pro)->update(['is_frozen' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('frozen');
        $this->ledger->confirmTopup($this->topup($pro, 1000));
    }

    public function test_completing_an_order_through_the_order_service_settles_commission(): void
    {
        $pro = $this->tradesperson();
        $this->ledger->confirmTopup($this->topup($pro, 5000));
        $order = $this->completedOrder($pro, 8000, 960);
        DB::table('orders')->where('id', $order->id)->update(['status' => OrderStatus::InProgress->value]);

        app(\App\Services\Orders\OrderService::class)->transition($order->fresh(), OrderStatus::Completed);

        $this->assertDatabaseHas('commission_entries', ['order_id' => $order->id, 'status' => 'settled']);
        $this->assertSame(4040, $this->ledger->walletFor($pro)->fresh()->available_balance);
        $this->assertLedgerBalanced();
    }
}
