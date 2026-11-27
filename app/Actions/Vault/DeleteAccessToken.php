<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Models\AccessToken;
use App\Vault\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class DeleteAccessToken
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Delete a token. Its project links go with it, and the access log rows that name it are kept without a
     * reference. The audit entry keeps the name as a label, because the token is gone afterwards.
     */
    public function handle(AccessToken $token): void
    {
        DB::transaction(function () use ($token): void {
            $this->auditLogger->record(
                AuditAction::TokenDeleted,
                $token,
                ['name' => $token->name, 'token_prefix' => $token->token_prefix],
                subjectLabel: $token->name,
            );

            $token->delete();
        });
    }
}
