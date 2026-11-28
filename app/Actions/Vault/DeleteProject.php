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
     * Delete a project. Its links to credentials and tokens are removed by the foreign key cascade.
     */
    public function handle(Project $project): void
    {
        Gate::authorize(Ability::ProjectsManage);

        DB::transaction(function () use ($project): void {
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
