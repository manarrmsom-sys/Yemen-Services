<?php

use App\Models\Order;
use App\Enums\OrderStatus;
use Illuminate\Support\Facades\Schedule;

// Expire orders that were never accepted after dispatch.
Schedule::call(function () {
    Order::whereIn('status', [OrderStatus::PendingDispatch->value, OrderStatus::Dispatched->value])
        ->where('created_at', '<', now()->subHours(3))
        ->update(['status' => OrderStatus::Expired->value]);
})->everyFifteenMinutes()->name('expire-stale-orders')->withoutOverlapping();

// Prune consumed / expired OTP codes.
Schedule::command('model:prune', ['--model' => [\App\Models\OtpCode::class]])->daily();
