<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // retries are self-managed via next_retry_at + re-dispatch

    public function __construct(public int $deliveryId) {}

    public function handle(): void
    {
        $delivery = WebhookDelivery::with('endpoint')->find($this->deliveryId);
        if (! $delivery || $delivery->status === 'success' || ! $delivery->endpoint) {
            return;
        }

        $endpoint = $delivery->endpoint;
        $body = json_encode($delivery->payload, JSON_UNESCAPED_UNICODE);
        $signature = WebhookDispatcher::sign($body, $endpoint->secret);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Webhook-Id' => $delivery->event_id,
                'X-Webhook-Event' => $delivery->event_type,
                'X-Webhook-Signature' => $signature,
                'X-Webhook-Attempt' => (string) $delivery->attempt,
            ])->timeout(config('platform.webhooks.timeout_seconds', 8))
                ->withBody($body, 'application/json')
                ->post($endpoint->url);

            if ($response->successful()) {
                $delivery->update([
                    'status' => 'success',
                    'response_status' => $response->status(),
                    'response_body' => mb_substr($response->body(), 0, 2000),
                    'delivered_at' => now(),
                ]);
                $endpoint->update([
                    'failure_count' => 0,
                    'last_success_at' => now(),
                ]);

                return;
            }

            $this->fail($delivery, $endpoint, "HTTP {$response->status()}", $response->status(), $response->body());
        } catch (\Throwable $e) {
            $this->fail($delivery, $endpoint, $e->getMessage());
        }
    }

    private function fail(WebhookDelivery $delivery, $endpoint, string $error, ?int $status = null, ?string $rawBody = null): void
    {
        $maxAttempts = (int) config('platform.webhooks.max_attempts', 5);
        $isDead = $delivery->attempt >= $maxAttempts;

        $delivery->update([
            'status' => $isDead ? 'dead' : 'failed',
            'response_status' => $status,
            'response_body' => $rawBody ? mb_substr($rawBody, 0, 2000) : null,
            'error' => $error,
            'next_retry_at' => $isDead ? null : now()->addSeconds(60 * (2 ** $delivery->attempt)),
        ]);

        $endpoint->increment('failure_count');
        $endpoint->update(['last_failure_at' => now()]);

        // Auto-disable a chronically failing endpoint.
        if ($endpoint->failure_count >= 20) {
            $endpoint->update(['disabled_at' => now(), 'is_active' => false]);
        }

        if (! $isDead) {
            $retry = WebhookDelivery::create([
                'webhook_endpoint_id' => $delivery->webhook_endpoint_id,
                'event_id' => $delivery->event_id,
                'event_type' => $delivery->event_type,
                'payload' => $delivery->payload,
                'attempt' => $delivery->attempt + 1,
                'status' => 'pending',
            ]);
            self::dispatch($retry->id)->delay($retry->created_at->addSeconds(60 * (2 ** $delivery->attempt)));
        }
    }
}
