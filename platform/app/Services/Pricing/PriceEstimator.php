<?php

namespace App\Services\Pricing;

use App\Enums\PricingModel;
use App\Enums\Urgency;
use App\Models\Governorate;
use App\Models\PricingRule;
use App\Models\Service;

/**
 * Dynamic price estimator.
 *
 * Transparent, deterministic pricing the customer sees BEFORE registering.
 * Order of operations:
 *   1. base subtotal from the service's pricing model + quantity/hours
 *   2. apply active pricing_rules (multiplier / flat_add / flat_override) by priority
 *   3. governorate dispatch multiplier -> governorate_adjustment
 *      (skipped for remote/digital categories: nobody travels)
 *   4. urgency surcharge (config percent) when urgency = urgent
 *   5. VAT (config percent) on (subtotal + adjustments + callout)
 *   6. round the customer-facing total to the configured step
 *   7. enforce the configured minimum order value
 */
class PriceEstimator
{
    public function estimate(Service $service, array $input): PriceQuote
    {
        $cfg = config('platform.pricing');
        $currency = config('platform.currency');

        $governorate = isset($input['governorate_id'])
            ? Governorate::find($input['governorate_id'])
            : null;

        $urgency = Urgency::tryFrom($input['urgency'] ?? 'today') ?? Urgency::Today;
        $quantity = max(0.0, (float) ($input['quantity'] ?? 1));
        $hours = max(0.0, (float) ($input['hours'] ?? $service->min_duration_minutes / 60));

        $lines = [];

        // --- 1. base subtotal ---
        if ($service->pricing_model === PricingModel::Quote) {
            return new PriceQuote(
                currency: $currency,
                subtotal: 0, calloutFee: $service->callout_fee, urgentSurcharge: 0,
                governorateAdjustment: 0, vatAmount: 0, discount: 0,
                total: $service->callout_fee, requiresQuote: true,
                lines: [$this->line('كشف ومعاينة', 'Assessment visit', $service->callout_fee)],
                inputs: $input,
            );
        }

        $subtotal = match ($service->pricing_model) {
            PricingModel::Fixed => $service->base_price,
            PricingModel::Hourly => (int) round($service->hourly_rate * max($hours, $service->min_duration_minutes / 60)),
            PricingModel::PerUnit => (int) round($service->unit_price * max($quantity, 1)),
            default => $service->base_price,
        };
        $lines[] = $this->line(
            $this->baseLabelAr($service, $quantity, $hours),
            $this->baseLabelEn($service, $quantity, $hours),
            $subtotal,
        );

        // --- 2. pricing rules ---
        $ruleContext = [
            'governorate_id' => $governorate?->id,
            'urgency' => $urgency->value,
            'weekday' => (int) now()->dayOfWeekIso,
        ];

        $rules = $service->relationLoaded('pricingRules')
            ? $service->pricingRules
            : $service->pricingRules()->where('is_active', true)->orderBy('priority')->get();

        foreach ($rules as $rule) {
            /** @var PricingRule $rule */
            if (! $rule->matches($ruleContext)) {
                continue;
            }
            $before = $subtotal;
            $subtotal = match ($rule->rule_type) {
                'multiplier' => (int) round($subtotal * $rule->value),
                'flat_add' => $subtotal + (int) $rule->value,
                'flat_override' => (int) $rule->value,
                default => $subtotal,
            };
            if ($subtotal !== $before) {
                $lines[] = $this->line("تعديل تسعيرة ({$rule->rule_type})", "Pricing rule ({$rule->rule_type})", $subtotal - $before);
            }
        }

        // --- 3. governorate adjustment ---
        $multiplier = $service->category?->is_remote ? 1.0 : ($governorate?->dispatch_multiplier ?? 1.0);
        $governorateAdjustment = (int) round($subtotal * ($multiplier - 1.0));
        if ($governorateAdjustment !== 0) {
            $lines[] = $this->line('رسوم توصيل حسب المحافظة', 'Governorate dispatch fee', $governorateAdjustment);
        }

        // --- callout fee ---
        $calloutFee = $service->callout_fee;
        if ($calloutFee > 0) {
            $lines[] = $this->line('رسوم الانتقال', 'Call-out fee', $calloutFee);
        }

        // --- 4. urgency surcharge ---
        $urgentSurcharge = 0;
        if ($urgency->appliesSurcharge()) {
            $urgentSurcharge = (int) round(($subtotal + $governorateAdjustment) * ($cfg['urgent_surcharge_percent'] / 100));
            $lines[] = $this->line('رسوم الخدمة العاجلة', 'Urgent service surcharge', $urgentSurcharge);
        }

        // --- 5. VAT ---
        $taxable = $subtotal + $governorateAdjustment + $calloutFee + $urgentSurcharge;
        $vatAmount = (int) round($taxable * ($cfg['vat_percent'] / 100));
        if ($vatAmount > 0) {
            $lines[] = $this->line('ضريبة القيمة المضافة', 'VAT', $vatAmount);
        }

        $discount = max(0, (int) ($input['discount'] ?? 0));
        if ($discount > 0) {
            $lines[] = $this->line('خصم', 'Discount', -$discount);
        }

        // --- 6 & 7. round + minimum ---
        $total = $subtotal + $governorateAdjustment + $calloutFee + $urgentSurcharge + $vatAmount - $discount;
        $total = $this->roundTo($total, $cfg['rounding_step']);
        $total = max($total, $cfg['min_order']);

        return new PriceQuote(
            currency: $currency,
            subtotal: $subtotal,
            calloutFee: $calloutFee,
            urgentSurcharge: $urgentSurcharge,
            governorateAdjustment: $governorateAdjustment,
            vatAmount: $vatAmount,
            discount: $discount,
            total: $total,
            requiresQuote: false,
            lines: $lines,
            inputs: $input,
        );
    }

    public function commissionFor(int $subtotal): array
    {
        $percent = (float) config('platform.pricing.commission_percent');

        return [
            'percent' => $percent,
            'amount' => (int) round($subtotal * $percent / 100),
        ];
    }

    private function roundTo(int $value, int $step): int
    {
        if ($step <= 1) {
            return $value;
        }

        return (int) (round($value / $step) * $step);
    }

    private function line(string $ar, string $en, int $amount): array
    {
        return ['label_ar' => $ar, 'label_en' => $en, 'amount' => $amount];
    }

    private function baseLabelAr(Service $s, float $qty, float $hours): string
    {
        return match ($s->pricing_model) {
            PricingModel::Hourly => sprintf('%s (%s ساعة)', $s->name_ar, rtrim(rtrim(number_format($hours, 1), '0'), '.')),
            PricingModel::PerUnit => sprintf('%s (%s %s)', $s->name_ar, $qty, $s->unit_label_ar ?? ''),
            default => $s->name_ar,
        };
    }

    private function baseLabelEn(Service $s, float $qty, float $hours): string
    {
        return match ($s->pricing_model) {
            PricingModel::Hourly => sprintf('%s (%s h)', $s->name_en, rtrim(rtrim(number_format($hours, 1), '0'), '.')),
            PricingModel::PerUnit => sprintf('%s (%s %s)', $s->name_en, $qty, $s->unit_label_en ?? ''),
            default => $s->name_en,
        };
    }
}
