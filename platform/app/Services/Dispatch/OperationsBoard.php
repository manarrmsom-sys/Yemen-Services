<?php

namespace App\Services\Dispatch;

use App\Enums\DispatchStatus;
use App\Models\DispatchJob;
use App\Models\Order;
use App\Models\TradespersonProfile;
use Illuminate\Support\Collection;

/**
 * Read model for the concierge operations room: which dispatches are waiting
 * and for how long, who else could take them, and how the operation is doing.
 */
class OperationsBoard
{
    private const WAITING = ['queued', 'sent', 'delivered'];

    public function __construct(private readonly DispatchService $dispatch) {}

    public function slaMinutes(): int
    {
        return (int) config('platform.dispatch.sla_minutes', 10);
    }

    /** Dispatch jobs grouped by status, each with its response timer. */
    public function board(): Collection
    {
        $sla = $this->slaMinutes();

        return DispatchJob::query()
            ->with([
                'order:id,reference,status,service_id,service_category_id,governorate_id,address_id,urgency,total_estimate,currency,dispatched_at,accepted_at,created_at,tradesperson_profile_id',
                'order.service:id,name_ar,name_en', 'order.governorate:id,code,name_ar,name_en',
                'order.address:id,district_id,district', 'tradesperson:id,display_name',
            ])
            ->whereIn('status', [...self::WAITING, DispatchStatus::Accepted->value])
            ->latest()->limit(300)->get()
            ->map(function (DispatchJob $job) use ($sla) {
                $order = $job->order;
                $waiting = in_array($job->status->value, self::WAITING, true) && $order && ! $order->accepted_at;
                $since = $order?->dispatched_at ?? $job->created_at;
                $minutes = $waiting ? (int) $since->diffInMinutes(now()) : null;

                return [
                    'id' => $job->id,
                    'status' => $job->status->value,
                    'mode' => $job->mode,
                    'wa_deeplink' => $job->wa_deeplink,
                    'tradesperson' => $job->tradesperson?->only('id', 'display_name'),
                    'waiting' => $waiting,
                    'waiting_since' => $waiting ? $since->toIso8601String() : null,
                    'waiting_minutes' => $minutes,
                    'overdue' => $waiting && $minutes >= $sla,
                    'order' => $order ? [
                        'reference' => $order->reference,
                        'urgency' => $order->urgency->value,
                        'total_estimate' => $order->total_estimate,
                        'currency' => $order->currency,
                        'service' => $order->service?->only('name_ar', 'name_en'),
                        'governorate' => $order->governorate?->only('code', 'name_ar', 'name_en'),
                        'district' => $order->address?->district,
                        'district_id' => $order->address?->district_id,
                    ] : null,
                ];
            })
            // Longest wait first inside each column.
            ->sortByDesc(fn (array $j) => $j['waiting_minutes'] ?? -1)->values()
            ->groupBy('status');
    }

    /**
     * Ready tradespeople who could take this job instead: same trade, same
     * district, verified, accepting jobs and funded — excluding whoever the job
     * was already sent to. Each comes with a pre-filled wa.me link.
     */
    public function alternates(DispatchJob $job, int $limit = 5): array
    {
        $order = $job->order()->with(['service', 'category', 'governorate', 'address', 'customer'])->first();
        $districtId = $order?->address?->district_id;
        if (! $order || ! $districtId) {
            return [];
        }

        return TradespersonProfile::query()
            ->where('kyc_status', 'verified')->where('is_accepting_jobs', true)
            ->where('primary_category_id', $order->service_category_id)
            ->where('base_district_id', $districtId)
            ->when($job->tradesperson_profile_id, fn ($q, $id) => $q->whereKeyNot($id))
            ->with(['user:id,phone', 'wallet'])
            ->orderByDesc('rating_avg')->orderByDesc('completed_orders')
            ->limit(30)->get()
            ->filter(fn (TradespersonProfile $p) => $p->readiness()['ready'] && filled($p->user?->phone))
            ->take($limit)
            ->map(fn (TradespersonProfile $p) => [
                'id' => $p->id,
                'display_name' => $p->display_name,
                'rating_avg' => $p->rating_avg,
                'completed_orders' => $p->completed_orders,
                'wa_deeplink' => $this->dispatch->buildWaDeeplink($order, $p),
            ])->values()->all();
    }

    /** Live KPIs over the trailing window. A value is null when there is no data yet. */
    public function kpis(int $days = 30): array
    {
        $from = now()->subDays($days);

        // 1. Average minutes from dispatch to acceptance.
        $accepted = Order::whereNotNull('accepted_at')->where('accepted_at', '>=', $from)
            ->get(['created_at', 'dispatched_at', 'accepted_at']);
        $acceptance = $accepted->isEmpty() ? null : round($accepted->avg(
            fn (Order $o) => max(0, ($o->dispatched_at ?? $o->created_at)->diffInSeconds($o->accepted_at)) / 60
        ), 1);

        // 2. Fulfilment: of the orders that reached an end state, how many were completed.
        $ended = Order::whereIn('status', ['completed', 'closed', 'cancelled', 'expired'])->where('created_at', '>=', $from)
            ->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $done = (int) ($ended['completed'] ?? 0) + (int) ($ended['closed'] ?? 0);
        $fulfilment = $ended->sum() ? round($done / $ended->sum() * 100, 1) : null;

        // 3. Estimate accuracy: 100% minus the mean absolute gap between the
        //    estimate shown to the customer and the final invoice.
        $priced = Order::whereNotNull('total_final')->where('total_estimate', '>', 0)->where('created_at', '>=', $from)
            ->get(['total_estimate', 'total_final']);
        $accuracy = $priced->isEmpty() ? null : round(max(0, 100 - $priced->avg(
            fn (Order $o) => abs($o->total_final - $o->total_estimate) / $o->total_estimate * 100
        )), 1);

        return [
            'window_days' => $days,
            'sla_minutes' => $this->slaMinutes(),
            'avg_acceptance_minutes' => $acceptance,
            'accepted_orders' => $accepted->count(),
            'fulfilment_rate' => $fulfilment,
            'ended_orders' => (int) $ended->sum(),
            'estimate_accuracy' => $accuracy,
            'priced_orders' => $priced->count(),
        ];
    }
}
