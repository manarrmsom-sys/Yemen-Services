<?php

namespace App\Policies;

use App\Models\TradespersonProfile;
use App\Models\User;

class TradespersonProfilePolicy
{
    public function before(User $user): ?bool
    {
        return $user->isStaff() ? true : null;
    }

    public function update(User $user, TradespersonProfile $profile): bool
    {
        return $profile->user_id === $user->id;
    }

    public function submitKyc(User $user, TradespersonProfile $profile): bool
    {
        return $profile->user_id === $user->id;
    }
}
