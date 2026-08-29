<?php

namespace App\Policies;

use App\Models\User;

class WebhookEndpointPolicy
{
    public function before(User $user): ?bool
    {
        // Only full admins manage the integration suite.
        return $user->isAdmin() ? true : false;
    }
}
