<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Models\Project;
use App\Vault\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class UpdateProject
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Update a project and audit the attributes that changed, with their old and new value.
     *
     * @param  array{name?: string, slug?: string, notes?: string|null}  $attributes
     */
    public function handle(Project $project, array $attributes): Project
    {
        return DB::transaction(function () use ($project, $attributes): Project {
            $project->fill($attributes);

            $changes = [];

            foreach (array_keys($project->getDirty()) as $attribute) {
                $changes[$attribute] = ['old' => $project->getOriginal($attribute), 'new' => $project->getAttribute($attribute)];
            }

            $project->save();

            $this->auditLogger->record(AuditAction::ProjectUpdated, $project, $changes);

            return $project;
        });
    }
}
