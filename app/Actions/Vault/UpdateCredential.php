<?php

namespace App\Actions\Vault;

use App\Exceptions\Vault\HostConflictException;
use App\Models\Credential;
use App\Vault\HostConflictGuard;
use App\Vault\HostName;
use Illuminate\Support\Facades\DB;

class UpdateCredential
{
    public function __construct(private HostConflictGuard $hostConflictGuard) {}

    /**
     * Update the non-secret attributes of a credential. Phase 2 extends this action with the secret.
     *
     * @param  array{name?: string, type?: string, host?: string, username?: string|null, notes?: string|null}  $attributes
     *
     * @throws HostConflictException
     */
    public function handle(Credential $credential, array $attributes): Credential
    {
        return DB::transaction(function () use ($credential, $attributes): Credential {
            if (isset($attributes['host'])) {
                $this->hostConflictGuard->assertCanChangeHost($credential, HostName::normalize($attributes['host']));
            }

            $credential->fill($attributes)->save();

            return $credential;
        });
    }
}
