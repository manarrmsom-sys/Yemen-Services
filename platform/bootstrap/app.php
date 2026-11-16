<?php

use App\Http\Middleware\EnforceJsonOnApi;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Illuminate\Support\Facades\Route::middleware('api')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Render terminates TLS at its edge proxy and forwards X-Forwarded-*.
        // Trusting the proxy chain is what makes Laravel see the request as
        // https: the session cookie, the XSRF-TOKEN pair and the real client
        // IP (used by the rate limiters) all depend on it. The app is only
        // reachable through that proxy, so every hop is trusted.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            SetLocale::class,
            SecurityHeaders::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->api(prepend: [
            EnforceJsonOnApi::class,
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->api(append: [
            SetLocale::class,
            SecurityHeaders::class,
        ]);

        $middleware->throttleApi('api');

        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'verified.trade' => \App\Http\Middleware\EnsureTradespersonVerified::class,
        ]);

        // CSRF is on by default for web routes; stateless webhook receivers opt out.
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // A throttled Inertia visit (e.g. repeated OTP requests) gets the page back
        // with a readable message instead of the raw 429 error screen.
        $exceptions->render(function (\Illuminate\Http\Exceptions\ThrottleRequestsException $e, Request $request) {
            if ($request->header('X-Inertia') && ! $request->expectsJson()) {
                return back()->with('error', __('auth.otp_throttled', [
                    'seconds' => (int) ($e->getHeaders()['Retry-After'] ?? 60),
                ]));
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            // Let the framework render these: 422 with per-field errors, and 401.
            // Without this they fell through to the generic branch below as a 500.
            if ($e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Illuminate\Auth\AuthenticationException) {
                return null;
            }

            if ($request->is('api/*') || $request->expectsJson()) {
                $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
                $status = $status >= 400 && $status < 600 ? $status : 500;

                return response()->json([
                    'message' => $status === 500 && ! config('app.debug')
                        ? __('errors.server')
                        : $e->getMessage(),
                ], $status);
            }
        });
    })->create();
