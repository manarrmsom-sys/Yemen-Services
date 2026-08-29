<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTradespersonVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $profile = $request->user()?->tradespersonProfile;

        abort_unless($profile, 403, __('errors.not_a_tradesperson'));
        abort_unless($profile->isVerified(), 403, __('errors.kyc_required'));

        return $next($request);
    }
}
