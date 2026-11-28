<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Models\Invitation;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RevokeInvitation
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Revoke an invitation. An invitation that was accepted or revoked already is left alone, and nothing is audited
     * again because nothing changed.
     */
    public function handle(Invitation $invitation): void
    {
        Gate::authorize(Ability::AdminsManage);

        if ($invitation->accepted_at !== null || $invitation->revoked_at !== null) {
            return;
        }

        DB::transaction(function () use ($invitation): void {
            $invitation->forceFill(['revoked_at' => now()])->save();

            $this->auditLogger->record(AuditAction::InvitationRevoked, $invitation);
        });
    }
}
