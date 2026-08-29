<?php

namespace App\Services\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Str;

/**
 * Fan a domain event out to every registered endpoint subscribed to it.
 * Each delivery is queued and retried with exponential backoff by DeliverWebhook.
 */
class WebhookDispatcher
{
    public function emit(string $eventType, array $data): void
    {
        $eventId = (string) Str::uuid();

        $endpoints = WebhookEndpoint::query()
            ->where('is_active', true)
            ->whereNull('disabled_at')
            ->get()
            ->filter->subscribesTo($eventType);

        foreach ($endpoints as $endpoint) {
            $delivery = WebhookDelivery::create([
                'webhook_endpoint_id' => $endpoint->id,
                'event_id' => $eventId,
                'event_type' => $eventType,
                'payload' => [
                    'id' => $eventId,
                    'type' => $eventType,
                    'created_at' => now()->toIso8601String(),
                    'data' => $data,
                ],
                'status' => 'pending',
            ]);

            DeliverWebhook::dispatch($delivery->id);
        }
    }

    public static function sign(string $body, string $secret): string
    {
        $algo = config('platform.webhooks.signing_algo', 'sha256');

        return $algo.'='.hash_hmac($algo, $body, $secret);
    }
}
