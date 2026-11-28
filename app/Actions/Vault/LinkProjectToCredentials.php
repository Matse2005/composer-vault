<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Exceptions\Vault\HostConflictException;
use App\Models\Project;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use App\Vault\HostConflictGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class LinkProjectToCredentials
{
    public function __construct(
        private HostConflictGuard $hostConflictGuard,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * Replace the credentials a project is linked to.
     *
     * @param  array<int, string>  $credentialIds
     *
     * @throws HostConflictException
     */
    public function handle(Project $project, array $credentialIds): void
    {
        Gate::authorize(Ability::ManageVault);

        DB::transaction(function () use ($project, $credentialIds): void {
            $this->hostConflictGuard->assertCanLinkToCredentials($project, $credentialIds);

            $result = $project->credentials()->sync($credentialIds);

            $this->auditLogger->record(AuditAction::CredentialLinksChanged, $project, [
                'added' => array_map('strval', $result['attached']),
                'removed' => array_map('strval', $result['detached']),
            ]);
        });
    }
}
