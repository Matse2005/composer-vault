<?php

namespace App\Vault;

use App\Exceptions\Vault\HostConflictException;
use App\Models\Credential;
use App\Models\Project;

/**
 * Composer can use one set of credentials per host, so a project may not hold two credentials for the same host.
 * Call these checks inside a transaction, before writing any link.
 */
class HostConflictGuard
{
    /**
     * Check that a credential can be linked to exactly these projects.
     *
     * @param  array<int, string>  $projectIds
     *
     * @throws HostConflictException
     */
    public function assertCanLinkToProjects(Credential $credential, array $projectIds): void
    {
        $this->assertHostIsFreeInProjects($credential, $credential->host, $projectIds);
    }

    /**
     * Check that a credential can move to a new host without clashing inside the projects it is linked to.
     *
     * @throws HostConflictException
     */
    public function assertCanChangeHost(Credential $credential, string $newHost): void
    {
        $projectIds = $credential->projects()->pluck('projects.id')->all();

        $this->assertHostIsFreeInProjects($credential, $newHost, $projectIds);
    }

    /**
     * Check that a project can be linked to exactly these credentials.
     *
     * @param  array<int, string>  $credentialIds
     *
     * @throws HostConflictException
     */
    public function assertCanLinkToCredentials(Project $project, array $credentialIds): void
    {
        $seenHosts = [];

        foreach (Credential::query()->whereKey($credentialIds)->get(['id', 'host']) as $credential) {
            if (isset($seenHosts[$credential->host])) {
                throw new HostConflictException($project->name, $credential->host);
            }

            $seenHosts[$credential->host] = true;
        }
    }

    /**
     * @param  array<int, string>  $projectIds
     */
    private function assertHostIsFreeInProjects(Credential $credential, string $host, array $projectIds): void
    {
        $conflictingProject = Project::query()
            ->whereKey($projectIds)
            ->whereHas('credentials', function ($query) use ($credential, $host): void {
                $query->where('credentials.host', $host)->whereKeyNot($credential->getKey());
            })
            ->first();

        if ($conflictingProject !== null) {
            throw new HostConflictException($conflictingProject->name, $host);
        }
    }
}
