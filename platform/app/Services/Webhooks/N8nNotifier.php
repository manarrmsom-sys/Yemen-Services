<?php

namespace App\Services\Webhooks;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Direct, signed push to an n8n webhook URL (HMAC-SHA256 of the raw body in
 * X-Signature, keyed by N8N_WEBHOOK_SECRET — the same scheme n8n already
 * verifies for dispatch). Never throws: a notification failure must not fail
 * the request that triggered it.
 */
class N8nNotifier
{
    public function send(?string $url, string $event, array $data): bool
    {
        if (! $url) {
            return false;
        }

        $body = json_encode([
            'event' => $event,
            'created_at' => now()->toIso8601String(),
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);

        try {
            return Http::withHeaders([
                'X-Signature' => hash_hmac('sha256', $body, (string) config('platform.n8n.secret')),
                'X-Event' => $event,
            ])->timeout(5)->withBody($body, 'application/json')->post($url)->successful();
        } catch (\Throwable $e) {
            Log::warning('[n8n] notification failed', ['event' => $event, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
