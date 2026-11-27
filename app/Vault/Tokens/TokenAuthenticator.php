<?php

namespace App\Vault\Tokens;

use App\Enums\AccessResult;
use App\Models\AccessToken;
use SensitiveParameter;

/**
 * Checks a bearer token against the stored hashes. It decides nothing about HTTP status codes: phase 3 maps the
 * result to a response.
 */
class TokenAuthenticator
{
    /**
     * A fixed hash to compare against when the prefix is unknown, so unknown prefixes cost about as much as
     * known ones.
     */
    private const DUMMY_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function authenticate(#[SensitiveParameter] ?string $bearer, string $ip): TokenAuthenticationResult
    {
        if ($bearer === null || preg_match('/^cv_[A-Za-z0-9]{40}\z/', $bearer) !== 1) {
            return $this->rejected(null, AccessResult::UnknownToken);
        }

        $token = AccessToken::query()->where('token_prefix', TokenIssuer::prefix($bearer))->first();
        $hashMatches = hash_equals($token->token_hash ?? self::DUMMY_HASH, TokenIssuer::hash($bearer));

        if ($token === null || ! $hashMatches) {
            return $this->rejected(null, AccessResult::UnknownToken);
        }

        if ($token->isRevoked()) {
            return $this->rejected($token, AccessResult::Revoked);
        }

        if ($token->isExpired()) {
            return $this->rejected($token, AccessResult::Expired);
        }

        if (! IpAllowlist::allows($token->allowed_ips ?? [], $ip)) {
            return $this->rejected($token, AccessResult::IpDenied);
        }

        $this->recordUse($token, $ip);

        return new TokenAuthenticationResult($token, AccessResult::Ok);
    }

    private function rejected(?AccessToken $token, AccessResult $result): TokenAuthenticationResult
    {
        return new TokenAuthenticationResult($token, $result);
    }

    /**
     * Store the last use without changing updated_at.
     */
    private function recordUse(AccessToken $token, string $ip): void
    {
        AccessToken::withoutTimestamps(function () use ($token, $ip): void {
            $token->forceFill(['last_used_at' => now(), 'last_used_ip' => $ip])->saveQuietly();
        });
    }
}
