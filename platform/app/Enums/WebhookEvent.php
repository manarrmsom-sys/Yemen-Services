<?php

namespace App\Enums;

enum WebhookEvent: string
{
    case OrderCreated = 'order.created';
    case OrderDispatched = 'order.dispatched';
    case OrderAccepted = 'order.accepted';
    case OrderCompleted = 'order.completed';
    case OrderCancelled = 'order.cancelled';
    case KycSubmitted = 'kyc.submitted';
    case KycVerified = 'kyc.verified';
    case KycRejected = 'kyc.rejected';
    case WalletTopupConfirmed = 'wallet.topup_confirmed';
    case CommissionSettled = 'commission.settled';

    /** @return string[] */
    public static function values(): array
    {
        return array_map(fn (self $e) => $e->value, self::cases());
    }
}
