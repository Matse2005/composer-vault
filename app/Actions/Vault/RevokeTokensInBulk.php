<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Models\AccessToken;
use App\Models\Project;
use App\Models\User;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RevokeTokensInBulk
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Revoke every token that is not revoked yet: all of them, or the ones that can fetch one project. One audit entry
     * holds the number of tokens that were affected.
     *
     * @return int the number of tokens revoked
     */
    public function handle(User $actor, ?Project $project = null): int
    {
        Gate::forUser($actor)->authorize(Ability::IncidentsManage);

        return DB::transaction(function () use ($actor, $project): int {
            $query = AccessToken::query()->whereNull('revoked_at');

            if ($project !== null) {
                $query->whereHas('projects', fn ($projects) => $projects->whereKey($project->getKey()));
            }

            $count = $query->update(['revoked_at' => now()]);

            $this->auditLogger->record(AuditAction::TokensRevokedInBulk, $project, ['count' => $count, 'scope' => $project === null ? 'all' : 'project'], $actor);

            return $count;
        });
    }
}
