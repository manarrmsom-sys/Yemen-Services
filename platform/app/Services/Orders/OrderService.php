<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\Urgency;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Service;
use App\Models\User;
use App\Services\Pricing\PriceEstimator;
use App\Services\Wallet\WalletService;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private readonly PriceEstimator $estimator,
        private readonly WalletService $wallet,
        private readonly WebhookDispatcher $webhooks,
    ) {}

    /**
     * Create an order from a completed 3-step booking.
     *
     * @param  array  $data  validated payload (service_id, governorate_id, address..., urgency, estimator inputs, contact)
     */
    public function createFromBooking(array $data, ?User $customer): Order
    {
        $service = Service::with('pricingRules', 'category')->findOrFail($data['service_id']);

        $quote = $this->estimator->estimate($service, [
            'governorate_id' => $data['governorate_id'],
            'urgency' => $data['urgency'] ?? 'today',
            'quantity' => $data['quantity'] ?? 1,
            'hours' => $data['hours'] ?? null,
        ]);

        $commission = $this->estimator->commissionFor($quote->subtotal);

        return DB::transaction(function () use ($data, $customer, $service, $quote, $commission) {
            $order = Order::create([
                'customer_id' => $customer?->id,
                'service_id' => $service->id,
                'service_category_id' => $service->service_category_id,
                'governorate_id' => $data['governorate_id'],
                'address_id' => $data['address_id'] ?? null,
                'status' => OrderStatus::PendingDispatch,
                'channel' => $data['channel'] ?? 'pwa',
                'urgency' => Urgency::tryFrom($data['urgency'] ?? 'today') ?? Urgency::Today,
                'scheduled_for' => $data['scheduled_for'] ?? null,
                'currency' => $quote->currency,
                'subtotal' => $quote->subtotal,
                'callout_fee' => $quote->calloutFee,
                'urgent_surcharge' => $quote->urgentSurcharge,
                'governorate_adjustment' => $quote->governorateAdjustment,
                'vat_amount' => $quote->vatAmount,
                'discount' => $quote->discount,
                'total_estimate' => $quote->total,
                'price_breakdown' => $quote->toArray(),
                'payment_method' => $data['payment_method'] ?? 'cod',
                'commission_percent' => $commission['percent'],
                'commission_amount' => $commission['amount'],
                'customer_notes' => $data['notes'] ?? null,
                'estimator_inputs' => $quote->inputs,
                'contact_name' => $data['contact_name'] ?? $customer?->name,
                'contact_phone' => $data['contact_phone'] ?? $customer?->phone,
                'created_ip' => request()->ip(),
            ]);

            $order->items()->create([
                'service_id' => $service->id,
                'name_snapshot' => $service->name_en,
                'pricing_model' => $service->pricing_model,
                'quantity' => $data['quantity'] ?? 1,
                'unit_price' => $quote->subtotal,
                'line_total' => $quote->subtotal,
                'options' => $data['options'] ?? null,
            ]);

            $this->logEvent($order, 'status_changed', null, OrderStatus::PendingDispatch->value, 'Order created from booking');

            $this->webhooks->emit('order.created', ['order' => $this->summary($order)]);

            return $order;
        });
    }

    public function transition(Order $order, OrderStatus $to, ?User $actor = null, ?string $note = null): Order
    {
        $from = $order->status;

        if (! $from->canTransitionTo($to)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot move order from {$from->value} to {$to->value}."],
            ]);
        }

        return DB::transaction(function () use ($order, $from, $to, $actor, $note) {
            $order->status = $to;

            match ($to) {
                OrderStatus::Accepted => $order->accepted_at = now(),
                OrderStatus::Completed => $order->completed_at = now(),
                OrderStatus::Cancelled => tap($order, fn ($o) => $o->cancelled_at = now())->cancellation_reason = $note,
                default => null,
            };

            $order->save();

            $this->logEvent($order, 'status_changed', $from->value, $to->value, $note, $actor);

            if ($to === OrderStatus::Completed) {
                $this->onCompleted($order);
            }

            $eventMap = [
                OrderStatus::Accepted->value => 'order.accepted',
                OrderStatus::Completed->value => 'order.completed',
                OrderStatus::Cancelled->value => 'order.cancelled',
            ];
            if (isset($eventMap[$to->value])) {
                $this->webhooks->emit($eventMap[$to->value], ['order' => $this->summary($order)]);
            }

            return $order;
        });
    }

    private function onCompleted(Order $order): void
    {
        if (! $order->tradesperson_profile_id) {
            return;
        }

        // Lock in the final total if none was provided.
        if ($order->total_final === null) {
            $order->forceFill(['total_final' => $order->total_estimate])->save();
        }

        $this->wallet->settleCompletedOrder($order);

        $profile = $order->tradesperson;
        $profile->increment('completed_orders');
        $profile->recalculateBadges();
        $profile->save();

        $order->forceFill(['status' => OrderStatus::Closed])->save();
        $this->logEvent($order, 'status_changed', OrderStatus::Completed->value, OrderStatus::Closed->value, 'Auto-closed after settlement');
    }

    public function logEvent(Order $order, string $type, ?string $from, ?string $to, ?string $message = null, ?User $actor = null): OrderEvent
    {
        return $order->events()->create([
            'type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'message' => $message,
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->role?->value,
            'created_at' => now(),
        ]);
    }

    public function summary(Order $order): array
    {
        return [
            'id' => $order->id,
            'reference' => $order->reference,
            'status' => $order->status->value,
            'service_id' => $order->service_id,
            'category_id' => $order->service_category_id,
            'governorate_id' => $order->governorate_id,
            'urgency' => $order->urgency->value,
            'total_estimate' => $order->total_estimate,
            'currency' => $order->currency,
            'payment_method' => $order->payment_method,
            'commission_amount' => $order->commission_amount,
            'contact_phone' => $order->contact_phone,
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }
}
