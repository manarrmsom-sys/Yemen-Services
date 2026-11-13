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

        RateLimiter::for('otp-request', function ($request) use ($limits) {
            // Requests are keyed by the email/phone; verifications by the session
            // token. (Verify calls carry no email/phone, so keying them on that
            // put every user in the world into one shared bucket.)
            $identity = $request->input('email') ?: $request->input('phone') ?: $request->input('session_token');

            return array_filter([
                Limit::perMinute($limits['otp_request'])->by('ip:'.$request->ip()),
                $identity ? Limit::perMinute(max(2, (int) ($limits['otp_request'] / 2)))->by('identity:'.sha1(strtolower((string) $identity))) : null,
            ]);
        });

        RateLimiter::for('track', fn ($request) => Limit::perMinute(30)->by($request->ip()));

        RateLimiter::for('home', fn ($request) => Limit::perMinute($limits['home'])->by($request->ip()));

        RateLimiter::for('booking', fn ($request) => Limit::perMinute($limits['booking'])
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('admin', fn ($request) => Limit::perMinute($limits['admin'])
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('webhooks', fn ($request) => Limit::perMinute(600)->by($request->ip()));
    }
}
