<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Exceptions\Vault\HostConflictException;
use App\Models\Credential;
use App\Vault\Audit\AuditLogger;
use App\Vault\HostConflictGuard;
use Illuminate\Support\Facades\DB;

class LinkCredentialToProjects
{
    public function __construct(
        private HostConflictGuard $hostConflictGuard,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * Replace the projects a credential is linked to.
     *
     * @param  array<int, string>  $projectIds
     *
     * @throws HostConflictException
     */
    public function handle(Credential $credential, array $projectIds): void
    {
        DB::transaction(function () use ($credential, $projectIds): void {
            $this->hostConflictGuard->assertCanLinkToProjects($credential, $projectIds);

            $result = $credential->projects()->sync($projectIds);

            $this->auditLogger->record(AuditAction::CredentialLinksChanged, $credential, [
                'added' => array_map('strval', $result['attached']),
                'removed' => array_map('strval', $result['detached']),
            ]);
        });
    }
}
