<?php

namespace App\Exceptions;

use App\Http\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Renders every exception on api/* as the error format of the API contract, never with a message or trace,
 * also when APP_DEBUG is true. Other requests are left to the default handler.
 */
class ApiExceptionRenderer
{
    public function __invoke(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return match (true) {
            $exception instanceof NotFoundHttpException,
            $exception instanceof MethodNotAllowedHttpException => ApiError::response(404, ApiError::NOT_FOUND),
            $exception instanceof TooManyRequestsHttpException => ApiError::response(429, ApiError::TOO_MANY_REQUESTS, $this->retryHeaders($exception)),
            default => ApiError::response(500, ApiError::SERVER_ERROR),
        };
    }

    /**
     * @return array<string, string>
     */
    private function retryHeaders(TooManyRequestsHttpException $exception): array
    {
        return array_intersect_key($exception->getHeaders(), array_flip(['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining']));
    }
}
