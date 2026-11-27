<?php

namespace App\Http\Middleware;

use App\Enums\AccessResult;
use App\Http\ApiError;
use App\Vault\RequestAttributes;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks an IP address for the rest of the minute after too many 401 and 403 responses. The check runs before
 * the token is looked at, so a valid token from a blocked address is refused as well.
 */
class BlockExcessiveFailures
{
    private const DECAY_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $key = 'vault-api-failures:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, (int) config('vault.api.failed_attempts_per_minute'))) {
            $request->attributes->set(RequestAttributes::ACCESS_RESULT, AccessResult::RateLimited);

            return ApiError::response(429, ApiError::TOO_MANY_REQUESTS, [
                'Retry-After' => (string) RateLimiter::availableIn($key),
            ]);
        }

        $response = $next($request);

        if (in_array($response->getStatusCode(), [401, 403], true)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
        }

        return $response;
    }
}
