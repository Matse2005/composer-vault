<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds the fixed security headers to every web response and starts a new Content Security Policy nonce for the
 * request, before any view is rendered. A package is not worth it for these fixed headers. The Content Security
 * Policy itself comes from spatie/laravel-csp, which runs after this middleware.
 */
class AddSecurityHeaders
{
    private const PERMISSIONS_POLICY = 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), publickey-credentials-create=(self), publickey-credentials-get=(self)';

    public function handle(Request $request, Closure $next): Response
    {
        $this->startNonce();

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', self::PERMISSIONS_POLICY);

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * Give this request its own nonce. Vite, Livewire and Flux read it while rendering, and the policy reads it
     * afterwards, so a nonce is never reused by a later request in the same process.
     */
    private function startNonce(): void
    {
        if (! config('csp.nonce_enabled', true)) {
            return;
        }

        Vite::useCspNonce();
        app()->forgetInstance('csp-nonce');
    }
}
