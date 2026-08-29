<?php

namespace App\Enums;

enum Urgency: string
{
    case Flexible = 'flexible';
    case Today = 'today';
    case Urgent = 'urgent';

    public function appliesSurcharge(): bool
    {
        return $this === self::Urgent;
    }
}
