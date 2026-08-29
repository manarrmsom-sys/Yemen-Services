<?php

namespace App\Enums;

enum PricingModel: string
{
    case Fixed = 'fixed';
    case Hourly = 'hourly';
    case PerUnit = 'per_unit';
    case Quote = 'quote';

    public function isEstimable(): bool
    {
        return $this !== self::Quote;
    }
}
