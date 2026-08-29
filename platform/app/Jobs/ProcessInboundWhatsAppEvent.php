<?php

namespace App\Jobs;

use App\Enums\DispatchStatus;
use App\Enums\OrderStatus;
use App\Models\DispatchJob;
use App\Models\InboundEvent;
use App\Models\Order;
use App\Models\User;
use App\Services\Dispatch\DispatchService;
use App\Services\Messaging\WhatsAppCloudChannel;
use App\Support\WhatsAppIntent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Parses an inbound WhatsApp Cloud API webhook payload and acts on it:
 *   - delivery / read statuses  -> advance the matching DispatchJob
 *   - free-text / button replies -> classify intent (accept / decline / status / cancel)
 *     and drive the dispatch + order state machine, then send a confirmation reply.
 *
 * Runs on the queue so the webhook receiver can return 200 immediately.
 */
class ProcessInboundWhatsAppEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(public int $inboundEventId) {}

    public function handle(DispatchService $dispatch, WhatsAppCloudChannel $wa): void
    {
        $event = InboundEvent::find($this->inboundEventId);
        if (! $event || $event->status === 'processed') {
            return;
        }

        $results = [];

        foreach (data_get($event->payload, 'entry', []) as $entry) {
            foreach (data_get($entry, 'changes', []) as $change) {
                $value = $change['value'] ?? [];

                foreach ($value['statuses'] ?? [] as $status) {
                    $results[] = $this->handleStatus($status);
                }

                foreach ($value['messages'] ?? [] as $message) {
                    $results[] = $this->handleMessage($message, $dispatch, $wa);
                }
            }
        }

        $event->update([
            'status' => 'processed',
            'processed_at' => now(),
            'note' => json_encode(array_values(array_filter($results)), JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function handleStatus(array $status): ?array
    {
        $waState = $status['status'] ?? null;           // sent | delivered | read | failed
        $recipient = $this->normalizePhone($status['recipient_id'] ?? '');
        if (! $waState || ! $recipient) {
            return null;
        }

        $map = [
            'sent' => DispatchStatus::Sent,
            'delivered' => DispatchStatus::Delivered,
            'read' => DispatchStatus::Delivered,
            'failed' => DispatchStatus::Failed,
        ];
        $target = $map[$waState] ?? null;
        if (! $target) {
            return null;
        }

        // Best effort: the most recent in-flight targeted job for this recipient.
        $job = DispatchJob::query()
            ->whereIn('status', [DispatchStatus::Queued->value, DispatchStatus::Sent->value])
            ->whereHas('tradesperson.user', fn ($q) => $q->where('phone', 'like', '%'.substr($recipient, -9)))
            ->latest('id')->first();

        if ($job && $this->rank($target) > $this->rank($job->status)) {
            $job->update(['status' => $target, 'sent_at' => $job->sent_at ?? now()]);

            return ['type' => 'status', 'dispatch_job' => $job->id, 'state' => $target->value];
        }

        return null;
    }

    private function handleMessage(array $message, DispatchService $dispatch, WhatsAppCloudChannel $wa): ?array
    {
        $wamid = $message['id'] ?? null;
        if ($wamid && ! Cache::add("wa:msg:{$wamid}", 1, now()->addHours(6))) {
            return ['type' => 'message', 'skipped' => 'duplicate', 'wamid' => $wamid];
        }

        $from = $this->normalizePhone($message['from'] ?? '');
        $text = $this->extractText($message);
        if (! $from) {
            return null;
        }

        $intent = WhatsAppIntent::parse($text);
        $locale = 'ar';

        $user = User::where('phone', 'like', '%'.substr($from, -9))->first();

        // --- Tradesperson replying to a dispatch offer ---
        $profile = $user?->tradespersonProfile;
        if ($profile) {
            $job = DispatchJob::query()
                ->whereIn('status', [DispatchStatus::Queued->value, DispatchStatus::Sent->value, DispatchStatus::Delivered->value])
                ->where(fn ($q) => $q->where('tradesperson_profile_id', $profile->id)
                    ->orWhere(fn ($w) => $w->whereNull('tradesperson_profile_id')->where('mode', 'broadcast')))
                ->whereHas('order', fn ($q) => $q->whereNull('tradesperson_profile_id')->whereNotIn('status', [
                    OrderStatus::Completed->value, OrderStatus::Closed->value,
                    OrderStatus::Cancelled->value, OrderStatus::Expired->value,
                ]))
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->latest('id')
                ->first();

            if (! $job) {
                return ['type' => 'message', 'role' => 'tradesperson', 'intent' => $intent, 'action' => 'no_open_job'];
            }

            $order = $job->order;

            return match ($intent) {
                WhatsAppIntent::ACCEPT => tap(
                    $dispatch->respondToDispatch($job, true, $profile, 'whatsapp_text'),
                    fn ($r) => $this->reply($wa, $from, $r['assigned']
                        ? $this->acceptedText($order->fresh(), $locale)
                        : $this->tooLateText($locale)),
                ) + ['type' => 'message', 'role' => 'tradesperson', 'intent' => 'accept'],

                WhatsAppIntent::DECLINE => tap(
                    $dispatch->respondToDispatch($job, false, $profile, 'whatsapp_text'),
                    fn () => $this->reply($wa, $from, $locale === 'ar'
                        ? 'تم تسجيل رفضك للطلب. شكرًا لك.'
                        : 'Your decline has been recorded. Thank you.'),
                ) + ['type' => 'message', 'role' => 'tradesperson', 'intent' => 'decline'],

                WhatsAppIntent::STATUS => tap(
                    ['type' => 'message', 'role' => 'tradesperson', 'intent' => 'status', 'order' => $order->reference],
                    fn () => $this->reply($wa, $from, $this->jobDetailsText($order, $locale)),
                ),

                default => tap(
                    ['type' => 'message', 'role' => 'tradesperson', 'intent' => 'unknown'],
                    fn () => $this->reply($wa, $from, $locale === 'ar'
                        ? "لم نفهم ردك. أرسل \"نعم\" لقبول الطلب {$order->reference} أو \"لا\" لرفضه."
                        : "We didn't understand. Reply \"yes\" to accept order {$order->reference} or \"no\" to decline."),
                ),
            };
        }

        // --- Customer replying about their own order ---
        $order = Order::query()
            ->where('contact_phone', 'like', '%'.substr($from, -9))
            ->latest('id')->first();

        if ($order) {
            if (in_array($intent, [WhatsAppIntent::DECLINE], true) && str_contains(WhatsAppIntent::normalize($text), 'الغ')) {
                $intent = 'cancel';
            }
            if ($this->isCancel($text)) {
                $intent = 'cancel';
            }

            if ($intent === 'cancel' && ! $order->status->isTerminal()) {
                $this->orders()->transition($order, OrderStatus::Cancelled, $order->customer, 'Cancelled by customer via WhatsApp');
                $this->reply($wa, $from, $locale === 'ar'
                    ? "تم إلغاء الطلب {$order->reference}."
                    : "Order {$order->reference} has been cancelled.");

                return ['type' => 'message', 'role' => 'customer', 'intent' => 'cancel', 'order' => $order->reference];
            }

            if ($intent === WhatsAppIntent::ACCEPT && $order->status === OrderStatus::Draft) {
                $this->orders()->transition($order, OrderStatus::PendingDispatch, $order->customer, 'Confirmed by customer via WhatsApp');
                $dispatch->dispatchOrder($order->fresh(), 'broadcast');
                $this->reply($wa, $from, $locale === 'ar'
                    ? "تم تأكيد طلبك {$order->reference}. يتم الآن البحث عن فني قريب."
                    : "Your order {$order->reference} is confirmed. We're finding a nearby pro now.");

                return ['type' => 'message', 'role' => 'customer', 'intent' => 'confirm', 'order' => $order->reference];
            }

            return ['type' => 'message', 'role' => 'customer', 'intent' => $intent, 'action' => 'no_op'];
        }

        Log::info('[whatsapp:inbound] message from unrecognised number', ['from' => $from, 'intent' => $intent]);

        return ['type' => 'message', 'role' => 'unknown', 'intent' => $intent];
    }

    // --- helpers ---

    private function orders(): \App\Services\Orders\OrderService
    {
        return app(\App\Services\Orders\OrderService::class);
    }

    private function extractText(array $message): string
    {
        return match ($message['type'] ?? 'text') {
            'text' => (string) data_get($message, 'text.body', ''),
            'button' => (string) data_get($message, 'button.text', data_get($message, 'button.payload', '')),
            'interactive' => (string) (data_get($message, 'interactive.button_reply.title')
                ?? data_get($message, 'interactive.button_reply.id')
                ?? data_get($message, 'interactive.list_reply.title', '')),
            default => '',
        };
    }

    private function isCancel(string $text): bool
    {
        $n = WhatsAppIntent::normalize($text);

        foreach (['الغاء', 'الغ', 'اريد الغاء', 'cancel', 'stop'] as $token) {
            if (str_contains($n, $token)) {
                return true;
            }
        }

        return false;
    }

    private function reply(WhatsAppCloudChannel $wa, string $to, string $body): void
    {
        try {
            $wa->send($to, $body);
        } catch (\Throwable $e) {
            Log::warning('[whatsapp:inbound] reply failed', ['to' => $to, 'error' => $e->getMessage()]);
        }
    }

    private function acceptedText(Order $order, string $locale): string
    {
        $addr = trim(($order->governorate?->{"name_{$locale}"} ?? '').' - '.($order->address?->district ?? ''));
        $directions = $order->address?->directions ? "\n".($locale === 'ar' ? 'الوصف: ' : 'Directions: ').$order->address->directions : '';

        return $locale === 'ar'
            ? "✅ تم إسناد الطلب {$order->reference} إليك.\n"
                ."العميل: {$order->contact_name} — {$order->contact_phone}\n"
                ."الموقع: {$addr}{$directions}\n"
                ."الإجمالي التقديري: ".number_format($order->total_estimate).' '.$order->currency."\n"
                .'الدفع: '.strtoupper($order->payment_method)
            : "✅ Order {$order->reference} is assigned to you.\n"
                ."Customer: {$order->contact_name} — {$order->contact_phone}\n"
                ."Location: {$addr}{$directions}\n"
                .'Estimated total: '.number_format($order->total_estimate).' '.$order->currency."\n"
                .'Payment: '.strtoupper($order->payment_method);
    }

    private function tooLateText(string $locale): string
    {
        return $locale === 'ar'
            ? 'نأسف، تم إسناد هذا الطلب لفني آخر.'
            : 'Sorry — this order was already assigned to another pro.';
    }

    private function jobDetailsText(Order $order, string $locale): string
    {
        $lines = [];
        foreach (data_get($order->price_breakdown, 'lines', []) as $line) {
            $label = $line[$locale === 'ar' ? 'label_ar' : 'label_en'] ?? '';
            $lines[] = "• {$label}: ".number_format($line['amount']).' '.$order->currency;
        }
        $gov = $order->governorate?->{"name_{$locale}"} ?? '';

        return ($locale === 'ar' ? "تفاصيل الطلب {$order->reference}\n" : "Order {$order->reference} details\n")
            .($locale === 'ar' ? "المحافظة: {$gov} - " : "Governorate: {$gov} - ").($order->address?->district ?? '')."\n"
            .implode("\n", $lines)."\n"
            .($locale === 'ar' ? 'الإجمالي: ' : 'Total: ').number_format($order->total_estimate).' '.$order->currency."\n"
            .($locale === 'ar' ? 'أرسل "نعم" للقبول أو "لا" للرفض.' : 'Reply "yes" to accept or "no" to decline.');
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone) ?: '';
    }

    private function rank(DispatchStatus $s): int
    {
        return match ($s) {
            DispatchStatus::Queued => 0,
            DispatchStatus::Sent => 1,
            DispatchStatus::Delivered => 2,
            DispatchStatus::Accepted => 3,
            DispatchStatus::Declined => 3,
            DispatchStatus::Failed => 1,
            DispatchStatus::Expired => 3,
        };
    }
}
