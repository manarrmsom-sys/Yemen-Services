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

        // The token covers the tradesperson id too, so a link sent to one pro
        // cannot be replayed with ?tp= to assign the job to somebody else.
        $tp = $request->query('tp');
        $token = (string) $request->query('t');
        $expected = DispatchService::callbackToken($order, $action, $tp !== null ? (int) $tp : null);
        abort_unless(hash_equals($expected, $token), 403);

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
