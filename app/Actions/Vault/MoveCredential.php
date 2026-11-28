<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Exceptions\Vault\HostConflictException;
use App\Models\Credential;
use App\Models\Project;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use App\Vault\HostConflictGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class MoveCredential
{
    public function __construct(private HostConflictGuard $hostConflictGuard, private AuditLogger $auditLogger) {}

    /**
     * Move a credential to another project. The secret is not touched: its ciphertext is bound to the credential ID,
     * which does not change. A host that the target project holds already is refused. Nothing is compared or copied
     * across projects.
     *
     * @throws HostConflictException
     */
    public function handle(Credential $credential, Project $target): void
    {
        Gate::authorize(Ability::CredentialsManage);

        DB::transaction(function () use ($credential, $target): void {
            $credential->refresh();
            $source = $credential->project_id;

            if ($source === $target->getKey()) {
                return;
            }

            $this->hostConflictGuard->assertHostIsFree($target, $credential->host);

            $credential->forceFill(['project_id' => $target->getKey()])->save();

            $this->auditLogger->record(AuditAction::CredentialMoved, $credential, ['from_project_id' => $source, 'to_project_id' => $target->getKey()]);
        });
    }
}
