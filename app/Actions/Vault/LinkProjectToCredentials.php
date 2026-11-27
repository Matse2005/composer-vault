<?php

namespace App\Actions\Vault;

use App\Exceptions\Vault\HostConflictException;
use App\Models\Project;
use App\Vault\HostConflictGuard;
use Illuminate\Support\Facades\DB;

class LinkProjectToCredentials
{
    public function __construct(private HostConflictGuard $hostConflictGuard) {}

    /**
     * Replace the credentials a project is linked to.
     *
     * @param  array<int, string>  $credentialIds
     *
     * @throws HostConflictException
     */
    public function handle(Project $project, array $credentialIds): void
    {
        DB::transaction(function () use ($project, $credentialIds): void {
            $this->hostConflictGuard->assertCanLinkToCredentials($project, $credentialIds);

            $project->credentials()->sync($credentialIds);
        });
    }
}
