<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Exceptions\Vault\HostConflictException;
use App\Models\Credential;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use App\Vault\HostConflictGuard;
use App\Vault\HostName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use SensitiveParameter;

class UpdateCredential
{
    /** The attributes of a credential that an update can change, besides the secret. */
    private const AUDITED_ATTRIBUTES = ['name', 'type', 'host', 'username', 'notes'];

    public function __construct(
        private HostConflictGuard $hostConflictGuard,
        private AuditLogger $auditLogger,
    ) {}

    /**
     * Update a credential. The secret is replaced only when a non-empty new secret is given. A credential cannot change
     * its project here: moving it is a separate action.
     *
     * One entry is audited for the update and a second one when the secret was replaced. Everything happens in one
     * transaction, so a host conflict leaves the credential unchanged.
     *
     * @param  array{name?: string, type?: mixed, host?: string, username?: string|null, notes?: string|null}  $attributes
     *
     * @throws HostConflictException
     */
    public function handle(Credential $credential, array $attributes, #[SensitiveParameter] ?string $newSecret = null): Credential
    {
        Gate::authorize(Ability::CredentialsManage);

        return DB::transaction(function () use ($credential, $attributes, $newSecret): Credential {
            if (isset($attributes['host'])) {
                $this->hostConflictGuard->assertHostIsFree($credential->project, HostName::normalize($attributes['host']), $credential);
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

            return $credential;
        });
    }
}
