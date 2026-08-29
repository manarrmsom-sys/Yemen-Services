<?php

namespace App\Enums;

enum KycStatus: string
{
    case Unverified = 'unverified';
    case Pending = 'pending';
    case InReview = 'in_review';
    case Verified = 'verified';
    case Rejected = 'rejected';

    public function isApproved(): bool
    {
        return $this === self::Verified;
    }
}
