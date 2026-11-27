<?php

namespace App\Vault\Tokens;

use App\Enums\AccessResult;
use App\Models\AccessToken;

/**
 * The outcome of authenticating a bearer token. The token is set for every result except an unknown token.
 */
final readonly class TokenAuthenticationResult
{
    public function __construct(public ?AccessToken $token, public AccessResult $result) {}

    public function isOk(): bool
    {
        return $this->result === AccessResult::Ok;
    }
}
