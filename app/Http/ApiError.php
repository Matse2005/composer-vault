<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;

/**
 * Builds the error responses of the API contract: the body is always {"error":"<code>"} with nothing else.
 */
final class ApiError
{
    public const UNAUTHENTICATED = 'unauthenticated';

    public const FORBIDDEN = 'forbidden';

    public const NOT_FOUND = 'not_found';

    public const TOO_MANY_REQUESTS = 'too_many_requests';

    public const SERVER_ERROR = 'server_error';

    /**
     * @param  array<string, string>  $headers
     */
    public static function response(int $status, string $code, array $headers = []): JsonResponse
    {
        return response()->json(['error' => $code], $status, $headers + [
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
