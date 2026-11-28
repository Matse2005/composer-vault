<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Models\Project;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteProject
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Delete a project together with its credentials and tokens (the foreign keys cascade). One audit entry is written
     * for every credential and every token first, then the entry of the project.
     */
    public function handle(Project $project): void
    {
        Gate::authorize(Ability::ProjectsManage);

        DB::transaction(function () use ($project): void {
            foreach ($project->credentials()->get() as $credential) {
                $this->auditLogger->record(AuditAction::CredentialDeleted, $credential, ['name' => $credential->name, 'host' => $credential->host, 'project_id' => $project->getKey(), 'with_project' => true], subjectLabel: $credential->name);
            }

            foreach ($project->accessTokens()->get() as $token) {
                $this->auditLogger->record(AuditAction::TokenDeleted, $token, ['name' => $token->name, 'token_prefix' => $token->token_prefix, 'project_id' => $project->getKey(), 'with_project' => true], subjectLabel: $token->name);
            }

            $this->auditLogger->record(
                AuditAction::ProjectDeleted,
                $project,
                ['name' => $project->name, 'slug' => $project->slug],
                subjectLabel: $project->name,
            );

            $project->delete();
        });
    }
}
