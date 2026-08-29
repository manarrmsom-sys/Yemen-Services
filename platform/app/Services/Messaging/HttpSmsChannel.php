<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Generic HTTP SMS gateway (local Yemeni aggregators typically expose a simple
 * GET/POST endpoint with api_key + sender + to + text). Configure via SMS_* env.
 */
class HttpSmsChannel implements MessageChannel
{
    public function send(string $toPhone, string $body, array $context = []): string
    {
        $cfg = config('platform.sms');

        if ($cfg['driver'] === 'log' || empty($cfg['endpoint'])) {
            Log::info('[sms:log] '.$toPhone.' => '.$body);

            return 'log-'.md5($toPhone.$body.microtime());
        }

        $response = Http::timeout(8)->retry(2, 250)->asForm()->post($cfg['endpoint'], [
            'api_key' => $cfg['api_key'],
            'sender' => $cfg['sender_id'],
            'to' => preg_replace('/[^0-9]/', '', $toPhone),
            'text' => $body,
        ]);

        if ($response->failed()) {
            Log::warning('[sms] send failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new RuntimeException('SMS send failed: '.$response->status());
        }

        return (string) data_get($response->json(), 'message_id', $response->body());
    }

    public function name(): string
    {
        return 'sms';
    }
}
