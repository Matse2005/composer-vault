<?php

namespace App\Http\Middleware;

use App\Enums\AdminRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Finishes what RestrictAdminIps started when the break-glass bypass is on and the address is not on the allowlist:
 * a guest may only reach the login pages, and a logged in admin only passes when they are a break-glass Owner.
 */
class EnforceBreakGlassForRestrictedIps
{
    /** Routes a guest may open from a restricted address, to log in as a break-glass Owner. */
    private const LOGIN_ROUTES = ['login', 'login.store', 'two-factor.*', 'passkey.*', 'passkeys.*', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get(RestrictAdminIps::RESTRICTED_ATTRIBUTE) !== true) {
            return $next($request);
        }

        $user = $request->user();

        $allowed = $user instanceof User
            ? $user->is_break_glass && $user->role === AdminRole::Owner
            : $request->routeIs(...self::LOGIN_ROUTES);

        if ($allowed) {
            return $next($request);
        }

        return response('Forbidden', 403, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
