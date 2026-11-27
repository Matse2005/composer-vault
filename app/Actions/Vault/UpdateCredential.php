<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Exceptions\Vault\HostConflictException;
use App\Models\Credential;
use App\Vault\Audit\AuditLogger;
use App\Vault\HostConflictGuard;
use App\Vault\HostName;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

class UpdateCredential
{
    /** The attributes of a credential that an update can change, besides the secret. */
    private const AUDITED_ATTRIBUTES = ['name', 'type', 'host', 'username', 'notes'];

    public function __construct(
        private HostConflictGuard $hostConflictGuard,
        private AuditLogger $auditLogger,
        private LinkCredentialToProjects $linkCredentialToProjects,
    ) {}

    /**
     * Update a credential. The secret is replaced only when a non-empty new secret is given.
     *
     * One entry is audited for the update, a second one when the secret was replaced, and a third one when the
     * projects are given and differ from the current ones. Everything happens in one transaction, so a host
     * conflict in the new projects leaves the credential unchanged.
     *
     * @param  array{name?: string, type?: mixed, host?: string, username?: string|null, notes?: string|null}  $attributes
     * @param  array<int, string>|null  $projectIds  The projects the credential should be linked to, or null to leave the links alone.
     *
     * @throws HostConflictException
     */
    public function handle(Credential $credential, array $attributes, #[SensitiveParameter] ?string $newSecret = null, ?array $projectIds = null): Credential
    {
        return DB::transaction(function () use ($credential, $attributes, $newSecret, $projectIds): Credential {
            if (isset($attributes['host'])) {
                $this->hostConflictGuard->assertCanChangeHost($credential, HostName::normalize($attributes['host']));
            }

            $credential->fill($attributes);

            $changes = [];

            foreach (array_intersect(array_keys($credential->getDirty()), self::AUDITED_ATTRIBUTES) as $attribute) {
                $changes[$attribute] = ['old' => $credential->getOriginal($attribute), 'new' => $credential->getAttribute($attribute)];
            }

            $secretChanged = $newSecret !== null && $newSecret !== '';

            if ($secretChanged) {
                $credential->setSecret($newSecret);
            }

            $credential->save();

            $this->auditLogger->record(AuditAction::CredentialUpdated, $credential, $changes);

            if ($secretChanged) {
                $this->auditLogger->record(AuditAction::CredentialSecretChanged, $credential, ['secret' => 'changed']);
            }

            if ($projectIds !== null && $this->linksDiffer($credential, $projectIds)) {
                $this->linkCredentialToProjects->handle($credential, $projectIds);
            }

            return $credential;
        });
    }

    /**
     * @param  array<int, string>  $projectIds
     */
    private function linksDiffer(Credential $credential, array $projectIds): bool
    {
        $current = $credential->projects()->pluck('projects.id')->sort()->values()->all();
        $wanted = collect($projectIds)->unique()->sort()->values()->all();

        return $current !== $wanted;
    }
}
