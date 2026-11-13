<?php

namespace App\Enums;

enum WalletType: string
{
    case Tradesperson = 'tradesperson';
    case PlatformEscrow = 'platform_escrow';
    case PlatformRevenue = 'platform_revenue';
    case PlatformClearing = 'platform_clearing';

    public function isSystem(): bool
    {
        return $this !== self::Tradesperson;
    }
}
