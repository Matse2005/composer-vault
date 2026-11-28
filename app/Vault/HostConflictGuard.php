<?php

namespace App\Vault;

use App\Exceptions\Vault\HostConflictException;
use App\Models\Credential;
use App\Models\Project;

/**
 * Composer can use one set of credentials per host, so a project may not hold two credentials for the same host. The
 * unique index on (project, host) enforces it; this check gives a clear message first. Call it inside a transaction.
 */
class HostConflictGuard
{
    /**
     * Check that the host is free in the project. The credential that is being changed does not count against itself.
     *
     * @throws HostConflictException
     */
    public function assertHostIsFree(Project $project, string $host, ?Credential $except = null): void
    {
        $taken = Credential::query()
            ->where('project_id', $project->getKey())
            ->where('host', $host)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except?->getKey()))
            ->exists();

        if ($taken) {
            throw new HostConflictException($project->name, $host);
        }
    }
}
