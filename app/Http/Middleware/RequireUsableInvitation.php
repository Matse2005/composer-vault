<?php

namespace App\Http\Middleware;

use App\Models\Invitation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers every invitation link that cannot be used with the same page and status 404: unknown, malformed, expired,
 * accepted and revoked tokens are indistinguishable.
 */
class RequireUsableInvitation
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->route('token');

        $invitation = Invitation::query()->where('token_hash', hash('sha256', $token))->first();

        if ($invitation === null || ! $invitation->isUsable()) {
            return response()->view('pages::auth.invitation-invalid', [], 404);
        }

        return $next($request);
    }
}
