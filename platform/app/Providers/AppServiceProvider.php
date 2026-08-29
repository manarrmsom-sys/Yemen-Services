<?php

namespace App\Providers;

use App\Models\Order;
use App\Models\TradespersonProfile;
use App\Models\WebhookEndpoint;
use App\Policies\OrderPolicy;
use App\Policies\TradespersonProfilePolicy;
use App\Policies\WebhookEndpointPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        $this->configureRateLimiting();

        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(TradespersonProfile::class, TradespersonProfilePolicy::class);
        Gate::policy(WebhookEndpoint::class, WebhookEndpointPolicy::class);
    }

    private function configureRateLimiting(): void
    {
        $limits = config('platform.rate_limits');

        RateLimiter::for('api', fn ($request) => Limit::perMinute($limits['api'])
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('otp-request', fn ($request) => [
            Limit::perMinute($limits['otp_request'])->by('ip:'.$request->ip()),
            Limit::perMinute(max(2, (int) ($limits['otp_request'] / 2)))->by('phone:'.sha1((string) $request->input('phone'))),
        ]);

        RateLimiter::for('booking', fn ($request) => Limit::perMinute($limits['booking'])
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('admin', fn ($request) => Limit::perMinute($limits['admin'])
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('webhooks', fn ($request) => Limit::perMinute(600)->by($request->ip()));
    }
}
