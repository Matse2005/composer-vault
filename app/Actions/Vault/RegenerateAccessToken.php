<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Exceptions\Vault\TokenIssuingFailedException;
use App\Models\AccessToken;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use App\Vault\Tokens\IssuedToken;
use App\Vault\Tokens\TokenIssuer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RegenerateAccessToken
{
    public function __construct(
        private TokenIssuer $tokenIssuer,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * Replace the token value in place. The old value stops working at once. Name, projects, IP allowlist and
     * expiry stay, the last use is reset, and a revoked token stays revoked.
     *
     * @throws TokenIssuingFailedException
     */
    public function handle(AccessToken $token): IssuedToken
    {
        Gate::authorize(Ability::ManageVault);

        return DB::transaction(function () use ($token): IssuedToken {
            $oldPrefix = $token->token_prefix;

            $token->forceFill(['last_used_at' => null, 'last_used_ip' => null]);

            $issued = $this->tokenIssuer->issue($token);

            $this->auditLogger->record(AuditAction::TokenRegenerated, $token, [
                'old_prefix' => $oldPrefix,
                'new_prefix' => $token->token_prefix,
            ]);

            return $issued;
        });
    }
}
