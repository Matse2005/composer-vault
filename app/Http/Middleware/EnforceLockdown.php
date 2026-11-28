<?php

namespace App\Http\Middleware;

use App\Actions\Vault\SetLockdown;
use App\Enums\AdminRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While a lockdown is on, the admin interface is for Owners only. Everyone else gets a 403, except for the logout.
 */
class EnforceLockdown
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->role !== AdminRole::Owner && ! $request->routeIs('logout') && SetLockdown::isOn()) {
            abort(403, __('The vault is locked down. Only Owners have access.'));
        }

        return $next($request);
    }
}
