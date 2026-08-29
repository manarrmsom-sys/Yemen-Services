<?php

namespace App\Enums;

enum UserRole: string
{
    case Customer = 'customer';
    case Tradesperson = 'tradesperson';
    case Dispatcher = 'dispatcher';
    case Admin = 'admin';

    public function isStaff(): bool
    {
        return in_array($this, [self::Dispatcher, self::Admin], true);
    }
}
