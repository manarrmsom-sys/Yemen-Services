<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\DispatchStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\DispatchJob;
use App\Models\InboundEvent;
use App\Models\Order;
use App\Models\WalletTopup;
use App\Services\Orders\OrderService;
use App\Services\Wallet\WalletService;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inbound automation callbacks from n8n.
 *
 * Topics:
 *   - dispatch-response  : a tradesperson accepted/declined via WhatsApp
 *   - topup-matched      : an SMS/bank transfer was auto-reconciled to a pending top-up
 *   - order-status       : field status update captured by an automation
 *
 * Auth: HMAC-SHA256 of the raw body in the X-Signature header, keyed by N8N_WEBHOOK_SECRET.
 */
class N8nInboundController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly WalletService $wallets,
        private readonly WebhookDispatcher $webhooks,
    ) {}

    public function __invoke(Request $request, string $topic): JsonResponse
    {
        $verified = $this->verify($request);
        abort_unless($verified, 401, 'invalid signature');

        $payload = $request->json()->all();

        $event = InboundEvent::create([
            'source' => 'n8n',
            'external_id' => $payload['execution_id'] ?? null,
            'signature_status' => 'verified',
            'payload' => $payload,
            'handler' => $topic,
            'status' => 'received',
        ]);

        $result = match ($topic) {
            'dispatch-response' => $this->handleDispatchResponse($payload),
            'topup-matched' => $this->handleTopupMatched($payload),
            'order-status' => $this->handleOrderStatus($payload),
            default => ['ignored' => true],
        };

        $event->update(['status' => 'processed', 'processed_at' => now(), 'note' => json_encode($result)]);

        return response()->json(['data' => $result]);
    }

    private function handleDispatchResponse(array $p): array
    {
        $job = DispatchJob::where('id', $p['dispatch_job_id'] ?? 0)
            ->orWhereHas('order', fn ($q) => $q->where('reference', $p['order_reference'] ?? ''))
            ->latest()->first();

        if (! $job) {
            return ['error' => 'dispatch job not found'];
        }

        $accepted = ($p['action'] ?? '') === 'accept';
        $job->update([
            'status' => $accepted ? DispatchStatus::Accepted : DispatchStatus::Declined,
            'responded_at' => now(),
            'tradesperson_profile_id' => $p['tradesperson_profile_id'] ?? $job->tradesperson_profile_id,
        ]);

        if ($accepted && $job->tradesperson_profile_id) {
            $order = $job->order;
            if ($order->tradesperson_profile_id === null) {
                $order->update(['tradesperson_profile_id' => $job->tradesperson_profile_id]);
                $this->orders->transition($order, OrderStatus::Accepted, null, 'Accepted via WhatsApp automation');
            }
        }

        return ['dispatch_job' => $job->id, 'status' => $job->status->value];
    }

    private function handleTopupMatched(array $p): array
    {
        $topup = WalletTopup::where('reference_code', $p['reference_code'] ?? '__none__')
            ->where('status', 'pending')->latest()->first();

        if (! $topup) {
            return ['error' => 'no matching pending top-up'];
        }

        $topup->update([
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'auto_matched_via' => $p['channel'] ?? 'n8n',
        ]);
        $group = $this->wallets->confirmTopup($topup);

        $this->webhooks->emit('wallet.topup_confirmed', [
            'topup_id' => $topup->id,
            'amount' => $topup->amount,
            'auto' => true,
        ]);

        return ['topup' => $topup->id, 'ledger_group' => $group];
    }

    private function handleOrderStatus(array $p): array
    {
        $order = Order::where('reference', $p['order_reference'] ?? '')->first();
        if (! $order || ! isset($p['status'])) {
            return ['error' => 'order or status missing'];
        }

        $target = OrderStatus::tryFrom($p['status']);
        if (! $target) {
            return ['error' => 'unknown status'];
        }

        $this->orders->transition($order, $target, null, $p['note'] ?? 'Updated via automation');

        return ['order' => $order->reference, 'status' => $target->value];
    }

    private function verify(Request $request): bool
    {
        $secret = (string) config('platform.n8n.secret');
        if ($secret === '') {
            return ! app()->environment('production');
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, (string) $request->header('X-Signature'));
    }
}
