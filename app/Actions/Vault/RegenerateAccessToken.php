<?php

namespace App\Actions\Vault;

use App\Exceptions\Vault\TokenIssuingFailedException;
use App\Models\AccessToken;
use App\Vault\Tokens\IssuedToken;
use App\Vault\Tokens\TokenIssuer;
use Illuminate\Support\Facades\DB;

class RegenerateAccessToken
{
    public function __construct(private TokenIssuer $tokenIssuer) {}

    /**
     * Replace the token value in place. The old value stops working at once. Name, projects, IP allowlist and
     * expiry stay, the last use is reset, and a revoked token stays revoked.
     *
     * @throws TokenIssuingFailedException
     */
    public function handle(AccessToken $token): IssuedToken
    {
        return DB::transaction(function () use ($token): IssuedToken {
            $token->forceFill(['last_used_at' => null, 'last_used_ip' => null]);

            return $this->tokenIssuer->issue($token);
        });
    }
}
