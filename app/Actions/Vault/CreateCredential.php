<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Exceptions\Vault\HostConflictException;
use App\Models\Credential;
use App\Vault\Audit\AuditLogger;
use App\Vault\HostConflictGuard;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

class CreateCredential
{
    public function __construct(
        private HostConflictGuard $hostConflictGuard,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * Create a credential with an encrypted secret and link it to projects, all or nothing.
     *
     * @param  array{name: string, type: mixed, host: string, username?: string|null, notes?: string|null}  $attributes
     * @param  array<int, string>  $projectIds
     *
     * @throws HostConflictException
     */
    public function handle(array $attributes, #[SensitiveParameter] string $secret, array $projectIds): Credential
    {
        return DB::transaction(function () use ($attributes, $secret, $projectIds): Credential {
            $credential = new Credential($attributes);
            $credential->setSecret($secret);
            $credential->save();

            $this->hostConflictGuard->assertCanLinkToProjects($credential, $projectIds);
            $credential->projects()->sync($projectIds);

            $this->auditLogger->record(AuditAction::CredentialCreated, $credential, [
                'name' => $credential->name,
                'type' => $credential->type,
                'host' => $credential->host,
                'username' => $credential->username,
                'project_ids' => array_values($projectIds),
                'secret' => 'changed',
            ]);

            return $credential;
        });
    }
}
