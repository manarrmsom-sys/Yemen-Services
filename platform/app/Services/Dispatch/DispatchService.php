<?php

namespace App\Services\Dispatch;

use App\Enums\DispatchStatus;
use App\Enums\OrderStatus;
use App\Models\DispatchJob;
use App\Models\Order;
use App\Models\TradespersonProfile;
use App\Services\Orders\OrderService;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * "The Gap Play" — dynamic WhatsApp geo-dispatch.
 *
 * For every order that needs a tradesperson we:
 *   1. build a pre-filled wa.me deep link (customer -> business, or business -> tradesperson)
 *      carrying the service category, a readable cost breakdown, and localized geo tags;
 *   2. persist a DispatchJob (audit + retry state);
 *   3. push a structured JSON payload to n8n (HMAC-signed) so an automation can
 *      fan the job out to matching tradespeople over WhatsApp/SMS and capture the reply.
 */
class DispatchService
{
    public function __construct(
        private readonly WebhookDispatcher $webhooks,
        private readonly OrderService $orders,
    ) {}

    public function dispatchOrder(Order $order, string $mode = 'targeted', ?TradespersonProfile $target = null): DispatchJob
    {
        $order->loadMissing(['service.category', 'governorate', 'address', 'category']);

        $payload = $this->buildPayload($order, $mode, $target);
        $deeplink = $this->buildWaDeeplink($order, $target);

        $job = DispatchJob::create([
            'order_id' => $order->id,
            'tradesperson_profile_id' => $target?->id,
            'mode' => $mode,
            'status' => DispatchStatus::Queued,
            'wa_deeplink' => $deeplink,
            'payload' => $payload,
            'geo_tags' => $payload['geo'],
            'attempt' => $order->dispatchJobs()->count() + 1,
            'expires_at' => now()->addMinutes(30),
        ]);

        $this->pushToN8n($job, $payload);

        if ($order->status === OrderStatus::PendingDispatch || $order->status === OrderStatus::Draft) {
            $order->forceFill([
                'status' => OrderStatus::Dispatched,
                'dispatched_at' => now(),
            ])->save();
        }

        $this->webhooks->emit('order.dispatched', [
            'order' => $this->orderSummary($order),
            'dispatch' => [
                'id' => $job->id,
                'mode' => $job->mode,
                'wa_deeplink' => $job->wa_deeplink,
                'geo' => $job->geo_tags,
            ],
        ]);

        return $job;
    }

    /** Structured payload for n8n / outbound webhooks. */
    public function buildPayload(Order $order, string $mode, ?TradespersonProfile $target): array
    {
        $locale = $order->customer?->preferred_locale ?? 'ar';
        $gov = $order->governorate;

        return [
            'event' => 'dispatch.requested',
            'mode' => $mode,
            'order' => $this->orderSummary($order),
            'breakdown' => $this->readableBreakdown($order, $locale),
            'geo' => [
                'governorate_code' => $gov?->code,
                'governorate_name' => $gov?->localizedName($locale),
                'district' => $order->address?->district,
                'lat' => $order->address?->lat ?? $gov?->center_lat,
                'lng' => $order->address?->lng ?? $gov?->center_lng,
                'directions' => $order->address?->directions,
                'geo_tag' => $this->geoTag($order),
            ],
            'target' => $target ? [
                'tradesperson_profile_id' => $target->id,
                'display_name' => $target->display_name,
                'phone' => $target->user?->phone,
            ] : null,
            'customer' => [
                'name' => $order->contact_name ?? $order->customer?->name,
                'phone' => $order->contact_phone ?? $order->customer?->phone,
                'locale' => $locale,
            ],
            'callbacks' => [
                'accept' => $this->signedCallback($order, 'accept', $target),
                'decline' => $this->signedCallback($order, 'decline', $target),
            ],
            'issued_at' => now()->toIso8601String(),
        ];
    }

    /** wa.me deep link with a URL-encoded, human-readable message. */
    public function buildWaDeeplink(Order $order, ?TradespersonProfile $target = null): string
    {
        $locale = $order->customer?->preferred_locale ?? 'ar';
        $to = $target?->user?->phone
            ?? config('platform.whatsapp.business_number');
        $to = preg_replace('/[^0-9]/', '', (string) $to);

        $lines = $locale === 'ar'
            ? [
                "🛠️ طلب خدمة جديد — {$order->reference}",
                "الخدمة: ".($order->service->name_ar ?? '').' / '.($order->category->name_ar ?? ''),
                "المحافظة: ".($order->governorate?->name_ar ?? '').' - '.($order->address?->district ?? ''),
                "الاستعجال: ".$this->urgencyLabel($order, 'ar'),
                '',
                "تفصيل التكلفة التقديرية:",
            ]
            : [
                "🛠️ New service request — {$order->reference}",
                "Service: ".($order->service->name_en ?? '').' / '.($order->category->name_en ?? ''),
                "Governorate: ".($order->governorate?->name_en ?? '').' - '.($order->address?->district ?? ''),
                "Urgency: ".$this->urgencyLabel($order, 'en'),
                '',
                "Estimated cost breakdown:",
            ];

        foreach ($this->readableBreakdown($order, $locale) as $row) {
            $lines[] = "• {$row['label']}: {$row['amount']}";
        }
        $lines[] = '';
        $lines[] = ($locale === 'ar' ? 'الإجمالي التقديري: ' : 'Estimated total: ')
            .number_format($order->total_estimate).' '.$order->currency;
        $lines[] = ($locale === 'ar' ? 'الدفع: ' : 'Payment: ').strtoupper($order->payment_method);

        $text = rawurlencode(implode("\n", $lines));

        return $to
            ? "https://wa.me/{$to}?text={$text}"
            : "https://wa.me/?text={$text}";
    }

    private function signedCallback(Order $order, string $action, ?TradespersonProfile $target): string
    {
        $token = hash_hmac('sha256', "{$order->reference}|{$action}", (string) config('app.key'));
        $params = ['order' => $order->reference, 'action' => $action, 't' => $token];
        if ($target) {
            $params['tp'] = $target->id;
        }

        return route('webhooks.dispatch.callback', $params);
    }

    public function markResponded(DispatchJob $job, DispatchStatus $status, array $meta = []): void
    {
        $job->update([
            'status' => $status,
            'responded_at' => now(),
            'payload' => array_merge((array) $job->payload, ['response' => $meta]),
        ]);
    }

    /**
     * Apply a tradesperson's accept/decline response to a dispatch job.
     * Shared by the n8n callback, the signed deep-link callback, and the
     * inbound free-text WhatsApp handler.
     *
     * @return array{status: string, order: string, assigned: bool}
     */
    public function respondToDispatch(DispatchJob $job, bool $accepted, ?TradespersonProfile $pro, string $via = 'callback'): array
    {
        return DB::transaction(function () use ($job, $accepted, $pro, $via) {
            $job->refresh();
            $order = $job->order()->lockForUpdate()->first();

            // Idempotency / race guard.
            if ($job->status === DispatchStatus::Accepted || $job->status === DispatchStatus::Declined) {
                return ['status' => $job->status->value, 'order' => $order->reference, 'assigned' => false];
            }
            if ($job->expires_at && $job->expires_at->isPast()) {
                $job->update(['status' => DispatchStatus::Expired]);

                return ['status' => 'expired', 'order' => $order->reference, 'assigned' => false];
            }

            if (! $accepted) {
                $this->markResponded($job, DispatchStatus::Declined, ['via' => $via, 'tradesperson_profile_id' => $pro?->id]);

                return ['status' => 'declined', 'order' => $order->reference, 'assigned' => false];
            }

            // Accept: only assign if the order is still unclaimed and the pro is eligible.
            $assigned = false;
            if ($pro && $order->tradesperson_profile_id === null && ! $order->status->isTerminal()) {
                $order->update(['tradesperson_profile_id' => $pro->id]);
                $job->update(['tradesperson_profile_id' => $pro->id]);

                if (in_array($order->status, [OrderStatus::PendingDispatch, OrderStatus::Dispatched], true)) {
                    $this->orders->transition($order, OrderStatus::Accepted, $pro->user, "Accepted via {$via}");
                }
                $assigned = true;
            }

            $this->markResponded($job, DispatchStatus::Accepted, ['via' => $via, 'tradesperson_profile_id' => $pro?->id]);

            // Losing bidders: mark any other open jobs for this order as expired.
            if ($assigned) {
                $order->dispatchJobs()
                    ->whereKeyNot($job->id)
                    ->whereIn('status', [DispatchStatus::Queued->value, DispatchStatus::Sent->value, DispatchStatus::Delivered->value])
                    ->update(['status' => DispatchStatus::Expired->value]);
            }

            return [
                'status' => $assigned ? 'accepted' : 'accepted_not_assigned',
                'order' => $order->reference,
                'assigned' => $assigned,
            ];
        });
    }

    private function pushToN8n(DispatchJob $job, array $payload): void
    {
        $url = config('platform.n8n.dispatch_url');
        if (! $url) {
            Log::info('[dispatch] n8n webhook not configured; deep link only', ['job' => $job->id]);

            return;
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $signature = hash_hmac('sha256', $body, (string) config('platform.n8n.secret'));

        try {
            $res = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Signature' => $signature,
                'X-Dispatch-Job' => (string) $job->id,
            ])->timeout(8)->withBody($body, 'application/json')->post($url);

            $job->update([
                'status' => $res->successful() ? DispatchStatus::Sent : DispatchStatus::Failed,
                'sent_at' => now(),
                'n8n_execution_id' => data_get($res->json(), 'executionId'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[dispatch] n8n push failed', ['job' => $job->id, 'error' => $e->getMessage()]);
            $job->update(['status' => DispatchStatus::Failed]);
        }
    }

    private function orderSummary(Order $order): array
    {
        return [
            'reference' => $order->reference,
            'id' => $order->id,
            'status' => $order->status->value,
            'service_id' => $order->service_id,
            'category_id' => $order->service_category_id,
            'urgency' => $order->urgency->value,
            'scheduled_for' => $order->scheduled_for?->toIso8601String(),
            'total_estimate' => $order->total_estimate,
            'currency' => $order->currency,
            'payment_method' => $order->payment_method,
        ];
    }

    private function readableBreakdown(Order $order, string $locale): array
    {
        $rows = [];
        $lines = data_get($order->price_breakdown, 'lines', []);
        foreach ($lines as $line) {
            $rows[] = [
                'label' => $line[$locale === 'ar' ? 'label_ar' : 'label_en'] ?? ($line['label_en'] ?? ''),
                'amount' => number_format($line['amount']).' '.$order->currency,
                'raw' => $line['amount'],
            ];
        }

        return $rows;
    }

    private function geoTag(Order $order): string
    {
        return implode(':', array_filter([
            'ye',
            $order->governorate?->code,
            $order->address?->district ? \Illuminate\Support\Str::slug($order->address->district) : null,
        ]));
    }

    private function urgencyLabel(Order $order, string $locale): string
    {
        return match ($order->urgency->value) {
            'urgent' => $locale === 'ar' ? 'عاجل (خلال ساعة)' : 'Urgent (within the hour)',
            'today' => $locale === 'ar' ? 'اليوم' : 'Today',
            default => $locale === 'ar' ? 'مرن' : 'Flexible',
        };
    }
}
