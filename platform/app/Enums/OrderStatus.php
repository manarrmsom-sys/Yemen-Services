<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Draft = 'draft';
    case PendingDispatch = 'pending_dispatch';
    case Dispatched = 'dispatched';
    case Accepted = 'accepted';
    case EnRoute = 'en_route';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /** Allowed forward transitions for the order state machine. */
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }

    /** @return self[] */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::PendingDispatch, self::Cancelled],
            self::PendingDispatch => [self::Dispatched, self::Cancelled, self::Expired],
            self::Dispatched => [self::Accepted, self::PendingDispatch, self::Cancelled, self::Expired],
            self::Accepted => [self::EnRoute, self::InProgress, self::Cancelled],
            self::EnRoute => [self::InProgress, self::Cancelled],
            self::InProgress => [self::Completed, self::Cancelled],
            self::Completed => [self::Closed],
            self::Closed, self::Cancelled, self::Expired => [],
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Closed, self::Cancelled, self::Expired], true);
    }

    public function isBillable(): bool
    {
        return in_array($this, [self::Completed, self::Closed], true);
    }
}
