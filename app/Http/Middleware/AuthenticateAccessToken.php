<?php

namespace App\Http\Middleware;

use App\Enums\AccessResult;
use App\Http\ApiError;
use App\Vault\RequestAttributes;
use App\Vault\Tokens\TokenAuthenticator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the bearer token and leaves the result and the token on the request. Missing, malformed,
 * unknown, revoked and expired tokens all give the same 401. A valid token from an address outside its
 * allowlist gives 403.
 */
class AuthenticateAccessToken
{
    public function __construct(private TokenAuthenticator $tokenAuthenticator) {}

    public function handle(Request $request, Closure $next): Response
    {
        $authentication = $this->tokenAuthenticator->authenticate($request->bearerToken(), (string) $request->ip());

        $request->attributes->set(RequestAttributes::ACCESS_RESULT, $authentication->result);
        $request->attributes->set(RequestAttributes::ACCESS_TOKEN, $authentication->token);

        return match ($authentication->result) {
            AccessResult::Ok => $next($request),
            AccessResult::IpDenied => ApiError::response(403, ApiError::FORBIDDEN),
            default => ApiError::response(401, ApiError::UNAUTHENTICATED),
        };
    }
}
