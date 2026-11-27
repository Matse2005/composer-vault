<?php

namespace App\Vault\Crypto;

use App\Exceptions\Vault\InvalidVaultKeyException;
use App\Exceptions\Vault\UnknownKeyVersionException;

interface KeyProvider
{
    /**
     * Get the master key that encrypts new data keys.
     *
     * @throws InvalidVaultKeyException
     */
    public function current(): MasterKey;

    /**
     * Get the master key with the given version, current or previous.
     *
     * @throws UnknownKeyVersionException
     * @throws InvalidVaultKeyException
     */
    public function forVersion(int $version): MasterKey;
}
