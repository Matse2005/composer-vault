<?php

namespace App\Actions\Vault;

use App\Models\AccessToken;

class RevokeAccessToken
{
    /**
     * Revoke a token. A token that is already revoked keeps its first revocation time.
     */
    public function handle(AccessToken $token): void
    {
        if ($token->isRevoked()) {
            return;
        }

        $token->forceFill(['revoked_at' => now()])->save();
    }
}
