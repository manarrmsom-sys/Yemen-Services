<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the request locale, in priority order:
 *   1. ?lang= query param (and persist to the session / cookie)
 *   2. X-Locale header (API clients)
 *   3. authenticated user's preferred_locale
 *   4. session value
 *   5. Accept-Language
 *   6. app default (ar)
 */
class SetLocale
{
    private const SUPPORTED = ['ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);

        app()->setLocale($locale);
        $request->attributes->set('locale', $locale);
        $request->attributes->set('dir', in_array($locale, config('app.rtl_locales', ['ar']), true) ? 'rtl' : 'ltr');

        $response = $next($request);

        if ($request->query('lang') && method_exists($response, 'withCookie')) {
            $response->withCookie(cookie('locale', $locale, 60 * 24 * 365, null, null, $request->secure(), false, false, 'lax'));
            $request->hasSession() && $request->session()->put('locale', $locale);
        }

        $response->headers->set('Content-Language', $locale);

        return $response;
    }

    private function resolve(Request $request): string
    {
        $candidates = [
            $request->query('lang'),
            $request->header('X-Locale'),
            $request->user()?->preferred_locale,
            $request->hasSession() ? $request->session()->get('locale') : null,
            $request->cookie('locale'),
            $request->getPreferredLanguage(self::SUPPORTED),
        ];

        foreach ($candidates as $candidate) {
            $candidate = is_string($candidate) ? strtolower(substr($candidate, 0, 2)) : null;
            if ($candidate && in_array($candidate, self::SUPPORTED, true)) {
                return $candidate;
            }
        }

        return config('app.locale', 'ar');
    }
}
