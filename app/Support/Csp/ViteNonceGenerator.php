<?php

namespace App\Support\Csp;

use Illuminate\Support\Facades\Vite;
use Spatie\Csp\Nonce\NonceGenerator;

/**
 * Gives the Content Security Policy the nonce that Vite puts on its script tags, so the header and every tag
 * (Vite, Livewire and Flux read the same nonce) share one value for the request.
 */
class ViteNonceGenerator implements NonceGenerator
{
    public function generate(): string
    {
        return Vite::cspNonce() ?? Vite::useCspNonce();
    }
}
