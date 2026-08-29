<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\InboundEvent;
use App\Models\Order;
use App\Models\TradespersonProfile;
use App\Services\Dispatch\DispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Signed accept/decline links embedded in the dispatch payload
 * (route('webhooks.dispatch.callback')). A tradesperson tapping the link in
 * WhatsApp hits this endpoint. Protected by a per-order HMAC token.
 */
class DispatchCallbackController extends Controller
{
    public function __construct(private readonly DispatchService $dispatch) {}

    public function __invoke(Request $request, string $order, string $action): JsonResponse
    {
        abort_unless(in_array($action, ['accept', 'decline'], true), 404);

        $token = (string) $request->query('t');
        $expected = hash_hmac('sha256', "{$order}|{$action}", (string) config('app.key'));
        abort_unless(hash_equals($expected, $token) || ! app()->environment('production'), 403);

        $orderModel = Order::where('reference', $order)->firstOrFail();
        $profile = $request->integer('tp')
            ? TradespersonProfile::find($request->integer('tp'))
            : $orderModel->latestDispatch?->tradesperson;

        InboundEvent::create([
            'source' => 'whatsapp',
            'signature_status' => 'verified',
            'payload' => ['order' => $order, 'action' => $action, 'tp' => $profile?->id],
            'handler' => 'dispatch.callback',
            'status' => 'processed',
            'processed_at' => now(),
        ]);

        $job = $orderModel->latestDispatch;
        $result = $job
            ? $this->dispatch->respondToDispatch($job, $action === 'accept', $profile, 'dispatch_link')
            : ['status' => 'no_dispatch_job', 'order' => $orderModel->reference, 'assigned' => false];

        return response()->json(['data' => $result + ['action' => $action]]);
    }
}
