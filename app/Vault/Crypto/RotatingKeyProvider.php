<?php

namespace App\Vault\Crypto;

use App\Exceptions\Vault\UnknownKeyVersionException;

/**
 * A temporary key provider for key rotation: the new key is current, and every other version still resolves
 * through the configured provider.
 */
class RotatingKeyProvider implements KeyProvider
{
    public function __construct(private MasterKey $newKey, private KeyProvider $inner) {}

    public function current(): MasterKey
    {
        return $this->newKey;
    }

    /**
     * @throws UnknownKeyVersionException
     */
    public function forVersion(int $version): MasterKey
    {
        if ($version === $this->newKey->version) {
            return $this->newKey;
        }

        return $this->inner->forVersion($version);
    }
}
