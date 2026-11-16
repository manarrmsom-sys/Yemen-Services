<?php

/**
 * Stateful SPA domains.
 *
 * The Vue app is served from the same origin as the API, so the browser must
 * be recognised as first-party for Sanctum to attach the session cookie and
 * enable CSRF protection. Domains are collected from SANCTUM_STATEFUL_DOMAINS,
 * APP_URL and RENDER_EXTERNAL_URL (which Render injects into every service),
 * so a deployment does not depend on a single env var being filled in — a
 * stale APP_URL (e.g. http://localhost) can no longer break the admin suite.
 */
$host = static function (?string $url): ?string {
    $url = trim((string) $url);
    if ($url === '') {
        return null;
    }

    $parts = parse_url(str_contains($url, '://') ? $url : 'https://'.$url);
    if (empty($parts['host'])) {
        return null;
    }

    return $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
};

$stateful = collect(explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', '')))
    ->map(fn (string $domain): string => trim($domain))
    ->filter()
    ->merge(['localhost', 'localhost:5173', '127.0.0.1', '127.0.0.1:8000', '::1'])
    ->push($host(env('APP_URL')))
    ->push($host(env('RENDER_EXTERNAL_URL')))
    // The host that actually served the request (custom domain, preview URL...).
    // Sanctum swaps this placeholder for the request host when matching.
    ->push(ltrim(Laravel\Sanctum\Sanctum::currentRequestHost(), ','))
    ->filter()
    ->unique()
    ->values()
    ->all();

return [
    'stateful' => $stateful,

    'guard' => ['web'],
    'expiration' => env('SANCTUM_TOKEN_EXPIRATION', 60 * 24 * 30),
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'ys_'),

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],
];
