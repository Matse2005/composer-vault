<?php

namespace App\Vault\Crypto;

/**
 * The stored form of a credential secret: the secret and its data key, both base64 encoded and encrypted.
 */
final readonly class EncryptedSecret
{
    public function __construct(
        public string $secretCiphertext,
        public string $dataKeyCiphertext,
        public int $keyVersion,
    ) {}
}
