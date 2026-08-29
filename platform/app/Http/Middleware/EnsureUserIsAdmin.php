<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * @param  string  ...$roles  extra roles allowed beyond admin (e.g. "dispatcher")
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $allowed = array_merge([UserRole::Admin->value], $roles);

        abort_unless($user && in_array($user->role->value, $allowed, true), 403, __('errors.forbidden'));
        abort_if($user->status !== 'active', 403, __('errors.account_suspended'));

        return $next($request);
    }
}
