<?php

namespace App\Vault\Crypto;

use App\Exceptions\Vault\InvalidVaultKeyException;
use App\Exceptions\Vault\UnknownKeyVersionException;
use Illuminate\Contracts\Config\Repository;

/**
 * Reads the master keys from the vault configuration (VAULT_KEY and VAULT_PREVIOUS_KEYS).
 *
 * The configuration is parsed on every call, so a changed value is picked up at once. Exception messages
 * never contain key material.
 */
class EnvKeyProvider implements KeyProvider
{
    public function __construct(private Repository $config) {}

    public function current(): MasterKey
    {
        $version = (int) $this->config->get('vault.key_version', 1);

        if ($version < 1) {
            throw new InvalidVaultKeyException('VAULT_KEY_VERSION must be a positive integer.');
        }

        return MasterKey::fromEncoded($version, $this->config->get('vault.key'), 'VAULT_KEY');
    }

    public function forVersion(int $version): MasterKey
    {
        $current = $this->current();

        if ($version === $current->version) {
            return $current;
        }

        return $this->previousKeys($current->version)[$version] ?? throw new UnknownKeyVersionException($version);
    }

    /**
     * @return array<int, MasterKey>
     *
     * @throws InvalidVaultKeyException
     */
    private function previousKeys(int $currentVersion): array
    {
        $configured = trim((string) $this->config->get('vault.previous_keys', ''));
        $keys = [];

        if ($configured === '') {
            return $keys;
        }

        foreach (explode(',', $configured) as $position => $entry) {
            $label = 'VAULT_PREVIOUS_KEYS entry '.($position + 1);
            [$version, $key] = array_pad(explode(':', trim($entry), 2), 2, null);

            if ($version === null || preg_match('/^[1-9][0-9]*$/', $version) !== 1) {
                throw new InvalidVaultKeyException("{$label} must start with a positive version number and a colon.");
            }

            if ((int) $version === $currentVersion || isset($keys[(int) $version])) {
                throw new InvalidVaultKeyException("{$label} repeats a key version.");
            }

            $keys[(int) $version] = MasterKey::fromEncoded((int) $version, $key, $label);
        }

        return $keys;
    }
}
