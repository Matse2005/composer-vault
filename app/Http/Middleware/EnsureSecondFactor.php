<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends every authenticated admin without a second factor (a confirmed authenticator app or at least one passkey) to
 * the security page, where it can be set up. Only the routes needed for that setup and for logging out stay open.
 */
class EnsureSecondFactor
{
    public const MESSAGE = 'Set up two-factor authentication or a passkey to continue.';

    /**
     * Route names (with wildcards) that stay reachable without a second factor.
     *
     * @var array<int, string>
     */
    public const ALLOWED_ROUTES = [
        'security.edit',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'logout',
        'two-factor.*',
        'passkey.*',
        'verification.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->hasSecondFactor() || $request->routeIs(...self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        return redirect()->route('security.edit')->with('second_factor_required', self::MESSAGE);
    }
}
