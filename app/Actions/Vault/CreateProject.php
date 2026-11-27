<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Models\Project;
use App\Vault\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class CreateProject
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Create a project. The slug is validated by the caller with the ProjectSlug rule.
     *
     * @param  array{name: string, slug: string, notes?: string|null}  $attributes
     */
    public function handle(array $attributes): Project
    {
        return DB::transaction(function () use ($attributes): Project {
            $project = Project::query()->create($attributes);

            $this->auditLogger->record(AuditAction::ProjectCreated, $project, [
                'name' => $project->name,
                'slug' => $project->slug,
                'notes' => $project->notes,
            ]);

            return $project;
        });
    }
}
