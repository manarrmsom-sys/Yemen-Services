<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundWhatsAppEvent;
use App\Models\InboundEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp Cloud API webhook.
 *  - GET  : Meta verification handshake (hub.challenge)
 *  - POST : delivery statuses + inbound messages (e.g. a tradesperson replying "نعم")
 */
class WhatsAppWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        if ($request->isMethod('get')) {
            $verifyToken = config('platform.whatsapp.webhook_verify_token');
            if ($request->query('hub_mode') === 'subscribe'
                && hash_equals((string) $verifyToken, (string) $request->query('hub_verify_token'))) {
                return response($request->query('hub_challenge'), 200);
            }

            return response('forbidden', 403);
        }

        // Verify Meta's X-Hub-Signature-256 against the app secret when configured.
        $signatureOk = $this->verifySignature($request);

        $event = InboundEvent::create([
            'source' => 'whatsapp',
            'external_id' => data_get($request->json()->all(), 'entry.0.id'),
            'signature_status' => $signatureOk ? 'verified' : 'unverified',
            'headers' => ['x-hub-signature-256' => $request->header('X-Hub-Signature-256')],
            'payload' => $request->json()->all(),
            'status' => 'received',
        ]);

        Log::info('[webhook:whatsapp] received', ['event' => $event->id, 'sig' => $event->signature_status]);

        // In production we only act on signed payloads; unsigned ones are still stored
        // for audit but not processed.
        if ($event->signature_status === 'verified' || ! app()->environment('production')) {
            ProcessInboundWhatsAppEvent::dispatch($event->id);
        } else {
            $event->update(['status' => 'ignored', 'note' => 'unverified signature']);
        }

        // Return 200 fast so Meta does not retry / disable the subscription.
        return response()->json(['received' => true]);
    }

    private function verifySignature(Request $request): bool
    {
        $secret = config('platform.whatsapp.access_token');
        $header = $request->header('X-Hub-Signature-256');

        if (! $secret || ! $header) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $header);
    }
}
