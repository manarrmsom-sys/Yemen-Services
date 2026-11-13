<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline hardening headers. HTTPS enforcement itself is done at Nginx/Cloudflare;
 * this adds HSTS + a conservative CSP compatible with the Vite/Inertia bundle.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-XSS-Protection' => '0',
            'Permissions-Policy' => 'geolocation=(self), camera=(), microphone=(), payment=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        if ($request->secure() || app()->environment('production')) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains; preload';
        }

        if (! $request->is('api/*') && ! $request->is('webhooks/*')) {
            $isLocal = app()->environment('local');

            $headers['Content-Security-Policy'] = implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline'".($isLocal ? " 'unsafe-eval' http://127.0.0.1:5173 http://localhost:5173" : ''),
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com".($isLocal ? ' http://127.0.0.1:5173 http://localhost:5173' : ''),
                "font-src 'self' https://fonts.gstatic.com data:",
                "img-src 'self' data: blob: https:",
                "connect-src 'self'".($isLocal ? ' ws://127.0.0.1:5173 http://127.0.0.1:5173 ws://localhost:5173 http://localhost:5173' : ''),
                "frame-ancestors 'none'",
                "base-uri 'self'",
                "form-action 'self'",
            ]);
        }

        foreach ($headers as $key => $value) {
            $response->headers->set($key, $value);
        }

        return $response;
    }
}
