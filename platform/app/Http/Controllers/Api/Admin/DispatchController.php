<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\DispatchStatus;
use App\Http\Controllers\Controller;
use App\Models\DispatchJob;
use App\Models\Order;
use App\Models\TradespersonProfile;
use App\Services\Dispatch\DispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DispatchController extends Controller
{
    public function __construct(
        private readonly DispatchService $dispatch,
        private readonly \App\Services\Dispatch\OperationsBoard $operations,
        private readonly \App\Services\Orders\OrderService $orders,
    ) {}

    public function dispatch(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'in:broadcast,targeted,manual'],
            'tradesperson_profile_id' => ['nullable', 'integer', 'exists:tradesperson_profiles,id'],
        ]);

        $target = isset($data['tradesperson_profile_id'])
            ? TradespersonProfile::find($data['tradesperson_profile_id'])
            : null;

        $job = $this->dispatch->dispatchOrder($order, $data['mode'], $target);

        return response()->json(['data' => [
            'id' => $job->id,
            'status' => $job->status->value,
            'wa_deeplink' => $job->wa_deeplink,
            'payload' => $job->payload,
        ]], 201);
    }

    /**
     * Operations-room board: jobs grouped by dispatch status with a response
     * timer each, plus the live KPIs shown above the board.
     */
    public function board(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->operations->board(),
            'kpis' => cache()->remember('admin:dispatch:kpis', 30, fn () => $this->operations->kpis()),
            'sla_minutes' => $this->operations->slaMinutes(),
        ]);
    }

    /** Ready tradespeople in the same district and trade, each with a wa.me link. */
    public function alternates(DispatchJob $job): JsonResponse
    {
        return response()->json(['data' => $this->operations->alternates($job)]);
    }

    /**
     * Audit trail for a one-tap WhatsApp reminder / re-route from the board.
     * The message itself is sent by the operator from WhatsApp; this records who
     * nudged whom so the order timeline shows the intervention.
     */
    public function nudge(Request $request, DispatchJob $job): JsonResponse
    {
        $data = $request->validate([
            'tradesperson_profile_id' => ['nullable', 'integer', 'exists:tradesperson_profiles,id'],
        ]);

        $target = isset($data['tradesperson_profile_id']) ? TradespersonProfile::find($data['tradesperson_profile_id']) : $job->tradesperson;
        $message = $target && $target->id !== $job->tradesperson_profile_id
            ? "Re-routed on WhatsApp to {$target->display_name} (#{$target->id})"
            : 'WhatsApp reminder sent'.($target ? " to {$target->display_name}" : '');

        $this->orders->logEvent($job->order, 'dispatch_nudge', null, null, $message, $request->user());
        $job->increment('attempt');

        return response()->json(['data' => ['ok' => true]], 201);
    }

    public function payload(DispatchJob $job): JsonResponse
    {
        return response()->json(['data' => [
            'payload' => $job->payload,
            'geo_tags' => $job->geo_tags,
            'wa_deeplink' => $job->wa_deeplink,
        ]]);
    }
}
