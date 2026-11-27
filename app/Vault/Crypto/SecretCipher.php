<?php

namespace App\Vault\Crypto;

use App\Exceptions\Vault\DecryptionFailedException;
use App\Exceptions\Vault\UnknownKeyVersionException;
use SensitiveParameter;
use SodiumException;

/**
 * Envelope encryption for credential secrets with XChaCha20-Poly1305.
 *
 * Every secret has its own random data key. The data key is encrypted with a master key. The credential ID is
 * bound into both ciphertexts as associated data, so a ciphertext copied to another credential fails to decrypt.
 * This is the only class that sees a secret in plaintext besides the API response builder and the admin form.
 */
class SecretCipher
{
    private const NONCE_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

    private const TAG_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;

    public function __construct(private KeyProvider $keyProvider) {}

    /**
     * Encrypt a secret under a new random data key, and the data key under the current master key.
     */
    public function encrypt(string $credentialId, #[SensitiveParameter] string $plaintext): EncryptedSecret
    {
        $masterKey = $this->keyProvider->current();
        $dataKey = random_bytes(MasterKey::KEY_BYTES);

        try {
            return new EncryptedSecret(
                secretCiphertext: $this->seal($plaintext, $this->secretContext($credentialId), $dataKey),
                dataKeyCiphertext: $this->seal($dataKey, $this->dataKeyContext($credentialId, $masterKey->version), $masterKey->bytes),
                keyVersion: $masterKey->version,
            );
        } finally {
            sodium_memzero($dataKey);
        }
    }

    /**
     * Decrypt a secret.
     *
     * @throws DecryptionFailedException On any failure, with a generic message.
     */
    public function decrypt(string $credentialId, #[SensitiveParameter] EncryptedSecret $encrypted): string
    {
        $dataKey = $this->unwrapDataKey($credentialId, $encrypted);

        try {
            return $this->open($encrypted->secretCiphertext, $this->secretContext($credentialId), $dataKey);
        } finally {
            sodium_memzero($dataKey);
        }
    }

    /**
     * Encrypt the data key again under the current master key. The secret ciphertext does not change.
     *
     * @throws DecryptionFailedException When the data key cannot be unwrapped.
     */
    public function rewrap(string $credentialId, #[SensitiveParameter] EncryptedSecret $encrypted): EncryptedSecret
    {
        $dataKey = $this->unwrapDataKey($credentialId, $encrypted);
        $masterKey = $this->keyProvider->current();

        try {
            return new EncryptedSecret(
                secretCiphertext: $encrypted->secretCiphertext,
                dataKeyCiphertext: $this->seal($dataKey, $this->dataKeyContext($credentialId, $masterKey->version), $masterKey->bytes),
                keyVersion: $masterKey->version,
            );
        } finally {
            sodium_memzero($dataKey);
        }
    }

    /**
     * @throws DecryptionFailedException
     */
    private function unwrapDataKey(string $credentialId, EncryptedSecret $encrypted): string
    {
        try {
            $masterKey = $this->keyProvider->forVersion($encrypted->keyVersion);
        } catch (UnknownKeyVersionException) {
            throw new DecryptionFailedException;
        }

        return $this->open(
            $encrypted->dataKeyCiphertext,
            $this->dataKeyContext($credentialId, $encrypted->keyVersion),
            $masterKey->bytes,
        );
    }

    /**
     * Encrypt with a random nonce and return base64(nonce . ciphertext).
     */
    private function seal(#[SensitiveParameter] string $plaintext, string $context, #[SensitiveParameter] string $key): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);

        return base64_encode($nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $context, $nonce, $key));
    }

    /**
     * Decode base64(nonce . ciphertext) and decrypt it.
     *
     * @throws DecryptionFailedException
     */
    private function open(#[SensitiveParameter] string $encoded, string $context, #[SensitiveParameter] string $key): string
    {
        $raw = base64_decode($encoded, true);

        if ($raw === false || strlen($raw) < self::NONCE_BYTES + self::TAG_BYTES) {
            throw new DecryptionFailedException;
        }

        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                substr($raw, self::NONCE_BYTES),
                $context,
                substr($raw, 0, self::NONCE_BYTES),
                $key,
            );
        } catch (SodiumException) {
            throw new DecryptionFailedException;
        }

        if ($plaintext === false) {
            throw new DecryptionFailedException;
        }

        return $plaintext;
    }

    private function secretContext(string $credentialId): string
    {
        return "composer-vault:secret:{$credentialId}";
    }

    private function dataKeyContext(string $credentialId, int $keyVersion): string
    {
        return "composer-vault:data-key:{$credentialId}:v{$keyVersion}";
    }
}
