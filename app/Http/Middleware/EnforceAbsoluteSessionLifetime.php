<?php

namespace App\Http\Middleware;

use App\Vault\Settings\VaultSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a session a fixed number of hours after the login, however active it is, on top of the idle timeout. With
 * single sign-on this forces a fresh check at the identity provider at least that often.
 */
class EnforceAbsoluteSessionLifetime
{
    public const SESSION_KEY = 'vault.logged_in_at';

    public function __construct(private VaultSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! Auth::check()) {
            return $next($request);
        }

        $session = $request->session();
        $loggedInAt = $session->get(self::SESSION_KEY);

        if ($loggedInAt === null) {
            $session->put(self::SESSION_KEY, now()->getTimestamp());

            return $next($request);
        }

        if ($loggedInAt + $this->settings->sessionAbsoluteHours() * 3600 > now()->getTimestamp()) {
            return $next($request);
        }

        Auth::guard()->logout();
        $session->invalidate();
        $session->regenerateToken();

        return redirect()->route('login')->with('status', __('Your session ended. Log in again.'));
    }
}
