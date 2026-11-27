<?php

namespace App\Vault\Crypto;

use App\Exceptions\Vault\InvalidVaultKeyException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;

/**
 * A vault master key. Dumps and serialisation only ever show the version, never the key bytes.
 */
final readonly class MasterKey implements JsonSerializable
{
    public const KEY_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;

    /**
     * @throws InvalidVaultKeyException When the key is not exactly 32 bytes.
     */
    public function __construct(public int $version, #[SensitiveParameter] public string $bytes)
    {
        if (strlen($bytes) !== self::KEY_BYTES) {
            throw new InvalidVaultKeyException('The vault key must be exactly '.self::KEY_BYTES.' bytes.');
        }
    }

    /**
     * @return array{version: int}
     */
    public function __debugInfo(): array
    {
        return ['version' => $this->version];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws LogicException Always, so the key cannot be serialised.
     */
    public function __serialize(): array
    {
        throw new LogicException('A master key cannot be serialized.');
    }

    /**
     * @return array{version: int}
     */
    public function jsonSerialize(): array
    {
        return ['version' => $this->version];
    }
}
