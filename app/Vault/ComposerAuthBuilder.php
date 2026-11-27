<?php

namespace App\Vault;

use App\Enums\CredentialType;
use App\Exceptions\Vault\DecryptionFailedException;
use App\Models\Credential;
use App\Models\Project;

/**
 * Builds the body of the composer-auth response: the credentials of one project in Composer's auth format.
 * This is the only place besides the cipher where a secret leaves the model in plaintext.
 */
class ComposerAuthBuilder
{
    /**
     * Group the credentials of a project by type and host. Only types with at least one entry are present.
     * A secret that cannot be decrypted fails the whole build, so no partial credentials are ever returned.
     *
     * @return array<string, array<string, array{username: string, password: string}|string>>
     *
     * @throws DecryptionFailedException
     */
    public function build(Project $project): array
    {
        $body = [];

        $credentials = $project->credentials()->orderBy('host')->get();

        foreach (CredentialType::cases() as $type) {
            $entries = [];

            foreach ($credentials->where('type', $type) as $credential) {
                $entries[$credential->host] = $this->entry($credential);
            }

            if ($entries !== []) {
                $body[$type->value] = $entries;
            }
        }

        return $body;
    }

    /**
     * @return array{username: string, password: string}|string
     *
     * @throws DecryptionFailedException
     */
    private function entry(Credential $credential): array|string
    {
        $secret = $credential->revealSecret();

        if ($credential->type === CredentialType::HttpBasic) {
            return ['username' => (string) $credential->username, 'password' => $secret];
        }

        return $secret;
    }
}
