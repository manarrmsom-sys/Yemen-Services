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
    public function __construct(private readonly DispatchService $dispatch) {}

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

    /** Kanban-style dispatch board grouped by dispatch status. */
    public function board(Request $request): JsonResponse
    {
        $jobs = DispatchJob::query()
            ->with(['order.service', 'order.governorate', 'tradesperson:id,display_name'])
            ->whereIn('status', [
                DispatchStatus::Queued->value, DispatchStatus::Sent->value,
                DispatchStatus::Delivered->value, DispatchStatus::Accepted->value,
            ])
            ->latest()
            ->limit(300)
            ->get()
            ->groupBy(fn ($j) => $j->status->value);

        return response()->json(['data' => $jobs]);
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
