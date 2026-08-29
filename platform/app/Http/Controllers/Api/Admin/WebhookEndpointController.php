<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\WebhookEvent;
use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhook;
use App\Models\InboundEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WebhookEndpointController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => WebhookEndpoint::withCount([
                'deliveries as failed_deliveries' => fn ($q) => $q->whereIn('status', ['failed', 'dead']),
            ])->latest()->get(),
            'available_events' => WebhookEvent::values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'url', 'max:1024', 'starts_with:https://,http://'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(array_merge(['*'], WebhookEvent::values()))],
            'is_active' => ['boolean'],
        ]);

        $endpoint = WebhookEndpoint::create($data + [
            'secret' => 'whsec_'.Str::random(40),
            'created_by' => $request->user()->id,
        ]);

        // Secret is shown once on creation.
        return response()->json(['data' => $endpoint->makeVisible('secret')], 201);
    }

    public function show(WebhookEndpoint $webhook): JsonResponse
    {
        return response()->json(['data' => $webhook]);
    }

    public function update(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        $data = $this->validate($request, [
            'name' => ['sometimes', 'string', 'max:120'],
            'url' => ['sometimes', 'url', 'max:1024'],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(array_merge(['*'], WebhookEvent::values()))],
            'is_active' => ['sometimes', 'boolean'],
            'rotate_secret' => ['sometimes', 'boolean'],
        ]);

        if ($data['rotate_secret'] ?? false) {
            $data['secret'] = 'whsec_'.Str::random(40);
        }
        unset($data['rotate_secret']);

        // Re-enable a previously auto-disabled endpoint.
        if (($data['is_active'] ?? false) && $webhook->disabled_at) {
            $data['disabled_at'] = null;
            $data['failure_count'] = 0;
        }

        $webhook->update($data);

        return response()->json(['data' => isset($data['secret']) ? $webhook->makeVisible('secret') : $webhook]);
    }

    public function destroy(WebhookEndpoint $webhook): JsonResponse
    {
        $webhook->delete();

        return response()->json(['data' => ['ok' => true]]);
    }

    public function test(WebhookEndpoint $webhook): JsonResponse
    {
        $delivery = $webhook->deliveries()->create([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'ping',
            'payload' => ['id' => (string) Str::uuid(), 'type' => 'ping', 'created_at' => now()->toIso8601String(), 'data' => ['message' => 'test event from admin']],
            'status' => 'pending',
        ]);

        DeliverWebhook::dispatchSync($delivery->id);

        return response()->json(['data' => $delivery->fresh()]);
    }

    public function deliveries(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        return response()->json(
            $webhook->deliveries()
                ->when($request->string('status')->isNotEmpty(), fn ($q) => $q->where('status', $request->string('status')))
                ->latest()
                ->paginate(30),
        );
    }

    public function retry(WebhookDelivery $delivery): JsonResponse
    {
        $delivery->update(['status' => 'pending', 'next_retry_at' => null]);
        DeliverWebhook::dispatch($delivery->id);

        return response()->json(['data' => $delivery->fresh()]);
    }

    public function inbound(Request $request): JsonResponse
    {
        return response()->json(
            InboundEvent::query()
                ->when($request->string('source')->isNotEmpty(), fn ($q) => $q->where('source', $request->string('source')))
                ->latest()
                ->paginate(30),
        );
    }
}
