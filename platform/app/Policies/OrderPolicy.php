<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isStaff() ? true : null;
    }

    public function view(User $user, Order $order): bool
    {
        return $order->customer_id === $user->id
            || $order->tradesperson?->user_id === $user->id;
    }

    public function update(User $user, Order $order): bool
    {
        return $order->tradesperson?->user_id === $user->id;
    }

    public function cancel(User $user, Order $order): bool
    {
        return $order->customer_id === $user->id && ! $order->status->isTerminal();
    }

    public function review(User $user, Order $order): bool
    {
        return $order->customer_id === $user->id && $order->status->isBillable();
    }
}
