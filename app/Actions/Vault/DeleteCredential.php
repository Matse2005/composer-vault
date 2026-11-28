<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Models\Credential;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteCredential
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Delete a credential. Its project links are removed by the foreign key cascade.
     */
    public function handle(Credential $credential): void
    {
        Gate::authorize(Ability::CredentialsManage);

        DB::transaction(function () use ($credential): void {
            $this->auditLogger->record(
                AuditAction::CredentialDeleted,
                $credential,
                ['name' => $credential->name, 'host' => $credential->host],
                subjectLabel: $credential->name,
            );

            $credential->delete();
        });
    }
}
