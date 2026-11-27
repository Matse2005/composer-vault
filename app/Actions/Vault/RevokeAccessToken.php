<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Models\AccessToken;
use App\Vault\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class RevokeAccessToken
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Revoke a token. A token that is already revoked keeps its first revocation time, and nothing is audited
     * again because nothing changed.
     */
    public function handle(AccessToken $token): void
    {
        if ($token->isRevoked()) {
            return;
        }

        DB::transaction(function () use ($token): void {
            $token->forceFill(['revoked_at' => now()])->save();

            $this->auditLogger->record(AuditAction::TokenRevoked, $token, ['token_prefix' => $token->token_prefix]);
        });
    }
}
