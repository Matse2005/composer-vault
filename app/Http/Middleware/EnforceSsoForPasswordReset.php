<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Vault\Sso\SsoConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While single sign-on is enforced, only break-glass Owners may reset a password. For everyone else the request
 * looks like it worked, so the page does not tell whether an address belongs to an admin.
 */
class EnforceSsoForPasswordReset
{
    public function __construct(private SsoConfig $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('password.email') || ! $this->config->enforced()) {
            return $next($request);
        }

        $user = User::query()->where('email', (string) $request->input('email'))->first();

        if ($user instanceof User && $this->config->allowsLocalLogin($user)) {
            return $next($request);
        }

        return back()->with('status', __('passwords.sent'));
    }
}
