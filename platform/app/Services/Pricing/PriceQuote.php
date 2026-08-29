<?php

namespace App\Services\Pricing;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Immutable value object returned by the estimator. All amounts are integer YER.
 *
 * @implements Arrayable<string, mixed>
 */
final class PriceQuote implements Arrayable
{
    /**
     * @param  array<int, array{label_ar: string, label_en: string, amount: int}>  $lines
     */
    public function __construct(
        public readonly string $currency,
        public readonly int $subtotal,
        public readonly int $calloutFee,
        public readonly int $urgentSurcharge,
        public readonly int $governorateAdjustment,
        public readonly int $vatAmount,
        public readonly int $discount,
        public readonly int $total,
        public readonly bool $requiresQuote,
        public readonly array $lines = [],
        public readonly array $inputs = [],
    ) {}

    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'callout_fee' => $this->calloutFee,
            'urgent_surcharge' => $this->urgentSurcharge,
            'governorate_adjustment' => $this->governorateAdjustment,
            'vat_amount' => $this->vatAmount,
            'discount' => $this->discount,
            'total' => $this->total,
            'requires_quote' => $this->requiresQuote,
            'lines' => $this->lines,
            'inputs' => $this->inputs,
        ];
    }
}
