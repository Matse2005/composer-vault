<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Exceptions\Vault\HostConflictException;
use App\Models\Credential;
use App\Models\Project;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use App\Vault\HostConflictGuard;
use App\Vault\HostName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use SensitiveParameter;

class CreateCredential
{
    public function __construct(
        private HostConflictGuard $hostConflictGuard,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * Create a credential with an encrypted secret in a project. A credential belongs to exactly one project, and a
     * project holds one credential per host.
     *
     * @param  array{name: string, type: mixed, host: string, username?: string|null, notes?: string|null}  $attributes
     *
     * @throws HostConflictException
     */
    public function handle(Project $project, array $attributes, #[SensitiveParameter] string $secret): Credential
    {
        Gate::authorize(Ability::CredentialsManage);

        return DB::transaction(function () use ($project, $attributes, $secret): Credential {
            $credential = new Credential($attributes);
            $credential->project_id = $project->getKey();
            $credential->setSecret($secret);

            $this->hostConflictGuard->assertHostIsFree($project, HostName::normalize($credential->host));

            $credential->save();

            $this->auditLogger->record(AuditAction::CredentialCreated, $credential, [
                'name' => $credential->name,
                'type' => $credential->type,
                'host' => $credential->host,
                'username' => $credential->username,
                'project_id' => $project->getKey(),
                'secret' => 'changed',
            ]);

            return $credential;
        });
    }
}
