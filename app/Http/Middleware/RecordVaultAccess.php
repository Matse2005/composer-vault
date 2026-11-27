<?php

namespace App\Http\Middleware;

use App\Vault\AccessLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes one access log row for every request, rejected ones included, from the request attributes that the
 * inner middleware and the controller have set. This is the outermost middleware after the headers, so
 * nothing inside it can skip the log.
 */
class RecordVaultAccess
{
    public function __construct(private AccessLogger $accessLogger) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $this->accessLogger->record($request);

        return $response;
    }
}
