<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * WhatsApp Cloud API (Meta Graph) transactional sender.
 * Used for OTP delivery and order notifications.
 */
class WhatsAppCloudChannel implements MessageChannel
{
    public function send(string $toPhone, string $body, array $context = []): string
    {
        $cfg = config('platform.whatsapp');

        if (empty($cfg['phone_number_id']) || empty($cfg['access_token'])) {
            // Fail soft in non-production so local dev keeps working.
            // The body can contain a login code, so it is only ever logged on a
            // developer machine — never on staging or production.
            if (app()->environment(['local', 'testing'])) {
                Log::info('[whatsapp:dev] message not sent (no credentials)', compact('toPhone', 'body', 'context'));

                return 'dev-'.md5($toPhone.$body.microtime());
            }
            throw new RuntimeException('WhatsApp Cloud API is not configured.');
        }

        $payload = isset($context['template'])
            ? $this->templatePayload($toPhone, $context)
            : $this->textPayload($toPhone, $body);

        $response = Http::withToken($cfg['access_token'])
            ->timeout(8)
            ->retry(2, 250)
            ->post("{$cfg['api_base']}/{$cfg['phone_number_id']}/messages", $payload);

        if ($response->failed()) {
            Log::warning('[whatsapp] send failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new RuntimeException('WhatsApp send failed: '.$response->status());
        }

        return data_get($response->json(), 'messages.0.id', 'unknown');
    }

    public function name(): string
    {
        return 'whatsapp';
    }

    private function textPayload(string $to, string $body): array
    {
        return [
            'messaging_product' => 'whatsapp',
            'to' => $this->normalize($to),
            'type' => 'text',
            'text' => ['body' => $body, 'preview_url' => false],
        ];
    }

    private function templatePayload(string $to, array $context): array
    {
        return [
            'messaging_product' => 'whatsapp',
            'to' => $this->normalize($to),
            'type' => 'template',
            'template' => [
                'name' => $context['template'],
                'language' => ['code' => $context['language'] ?? 'ar'],
                'components' => $context['components'] ?? [[
                    'type' => 'body',
                    'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], $context['params'] ?? []),
                ]],
            ],
        ];
    }

    private function normalize(string $phone): string
    {
        return ltrim(preg_replace('/[^0-9]/', '', $phone), '0');
    }
}
