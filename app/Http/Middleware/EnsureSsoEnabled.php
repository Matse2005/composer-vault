<?php

namespace App\Http\Middleware;

use App\Vault\Sso\SsoConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The single sign-on routes exist only while single sign-on is enabled and configured. Otherwise they are a 404, as if
 * they were not registered.
 */
class EnsureSsoEnabled
{
    public function __construct(private SsoConfig $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->config->isActive(), 404);

        return $next($request);
    }
}
