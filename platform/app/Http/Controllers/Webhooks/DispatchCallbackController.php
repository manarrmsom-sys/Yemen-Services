<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\DispatchStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\InboundEvent;
use App\Models\Order;
use App\Services\Orders\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Signed accept/decline links embedded in the dispatch payload
 * (route('webhooks.dispatch.callback')). A tradesperson tapping the link in
 * WhatsApp hits this endpoint. Protected by a per-order HMAC token.
 */
class DispatchCallbackController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function __invoke(Request $request, string $order, string $action): JsonResponse
    {
        abort_unless(in_array($action, ['accept', 'decline'], true), 404);

        $token = (string) $request->query('t');
        $expected = hash_hmac('sha256', "{$order}|{$action}", (string) config('app.key'));
        abort_unless(hash_equals($expected, $token) || ! app()->environment('production'), 403);

        $orderModel = Order::where('reference', $order)->firstOrFail();
        $profileId = $request->integer('tp') ?: null;

        InboundEvent::create([
            'source' => 'whatsapp',
            'signature_status' => 'verified',
            'payload' => ['order' => $order, 'action' => $action, 'tp' => $profileId],
            'handler' => 'dispatch.callback',
            'status' => 'processed',
            'processed_at' => now(),
        ]);

        $job = $orderModel->latestDispatch;
        $job?->update([
            'status' => $action === 'accept' ? DispatchStatus::Accepted : DispatchStatus::Declined,
            'responded_at' => now(),
            'tradesperson_profile_id' => $profileId ?? $job->tradesperson_profile_id,
        ]);

        if ($action === 'accept' && $orderModel->tradesperson_profile_id === null && $profileId) {
            $orderModel->update(['tradesperson_profile_id' => $profileId]);
            $this->orders->transition($orderModel, OrderStatus::Accepted, null, 'Accepted via dispatch link');
        }

        return response()->json(['data' => [
            'order' => $orderModel->reference,
            'status' => $orderModel->fresh()->status->value,
            'action' => $action,
        ]]);
    }
}
