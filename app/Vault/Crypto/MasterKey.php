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

    public const PREFIX = 'base64:';

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
     * Decode a key in the "base64:..." format, the same format as APP_KEY.
     *
     * The label names the setting in error messages, which never contain key material.
     *
     * @throws InvalidVaultKeyException
     */
    public static function fromEncoded(int $version, #[SensitiveParameter] mixed $value, string $label): self
    {
        if (! is_string($value) || $value === '') {
            throw new InvalidVaultKeyException("{$label} is missing.");
        }

        if (! str_starts_with($value, self::PREFIX)) {
            throw new InvalidVaultKeyException("{$label} must start with \"".self::PREFIX.'".');
        }

        $bytes = base64_decode(substr($value, strlen(self::PREFIX)), true);

        if ($bytes === false) {
            throw new InvalidVaultKeyException("{$label} is not valid base64.");
        }

        if (strlen($bytes) !== self::KEY_BYTES) {
            throw new InvalidVaultKeyException("{$label} must decode to exactly ".self::KEY_BYTES.' bytes.');
        }

        return new self($version, $bytes);
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
