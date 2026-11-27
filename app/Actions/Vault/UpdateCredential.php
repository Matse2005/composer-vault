<?php

namespace App\Actions\Vault;

use App\Exceptions\Vault\HostConflictException;
use App\Models\Credential;
use App\Vault\HostConflictGuard;
use App\Vault\HostName;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

class UpdateCredential
{
    public function __construct(private HostConflictGuard $hostConflictGuard) {}

    /**
     * Update a credential. The secret is replaced only when a non-empty new secret is given.
     *
     * @param  array{name?: string, type?: mixed, host?: string, username?: string|null, notes?: string|null}  $attributes
     *
     * @throws HostConflictException
     */
    public function handle(Credential $credential, array $attributes, #[SensitiveParameter] ?string $newSecret = null): Credential
    {
        return DB::transaction(function () use ($credential, $attributes, $newSecret): Credential {
            if (isset($attributes['host'])) {
                $this->hostConflictGuard->assertCanChangeHost($credential, HostName::normalize($attributes['host']));
            }

            $credential->fill($attributes);

            if ($newSecret !== null && $newSecret !== '') {
                $credential->setSecret($newSecret);
            }

            $credential->save();

            return $credential;
        });
    }
}
