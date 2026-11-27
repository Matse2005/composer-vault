<?php

use App\Exceptions\Vault\DecryptionFailedException;
use App\Vault\Crypto\EncryptedSecret;
use App\Vault\Crypto\EnvKeyProvider;
use App\Vault\Crypto\SecretCipher;
use Illuminate\Config\Repository;

function secretCipher(int $currentVersion = 1, string $previousKeys = ''): SecretCipher
{
    return new SecretCipher(new EnvKeyProvider(new Repository(['vault' => [
        'key' => vaultTestKey($currentVersion),
        'key_version' => $currentVersion,
        'previous_keys' => $previousKeys,
    ]])));
}

/**
 * Flip one bit in the middle of a base64 encoded ciphertext.
 */
function flipByte(string $encoded): string
{
    $raw = base64_decode($encoded, true);
    $middle = intdiv(strlen($raw), 2);
    $raw[$middle] = chr(ord($raw[$middle]) ^ 1);

    return base64_encode($raw);
}

it('round trips secrets', function (string $secret) {
    $cipher = secretCipher();

    $encrypted = $cipher->encrypt('01JCREDENTIAL000000000000A', $secret);

    expect($cipher->decrypt('01JCREDENTIAL000000000000A', $encrypted))->toBe($secret)
        ->and($encrypted->keyVersion)->toBe(1);
})->with([
    'empty' => [''],
    'short' => ['abc'],
    'long' => [fn () => str_repeat('long secret ', 5000)],
    'multibyte' => ['pässwörd-密码-🔑'],
    'binary' => [fn () => random_bytes(64)],
]);

it('gives different ciphertexts for the same secret', function () {
    $cipher = secretCipher();

    $first = $cipher->encrypt('01JCREDENTIAL000000000000A', 'same secret');
    $second = $cipher->encrypt('01JCREDENTIAL000000000000A', 'same secret');

    expect($first->secretCiphertext)->not->toBe($second->secretCiphertext)
        ->and($first->dataKeyCiphertext)->not->toBe($second->dataKeyCiphertext);
});

it('does not contain the plaintext in either ciphertext', function () {
    $encrypted = secretCipher()->encrypt('01JCREDENTIAL000000000000A', 'plaintext-marker-value');

    expect($encrypted->secretCiphertext)->not->toContain('plaintext-marker-value')
        ->and(base64_decode($encrypted->secretCiphertext, true))->not->toContain('plaintext-marker-value');
});

it('fails to decrypt with another credential id', function () {
    $cipher = secretCipher();
    $encrypted = $cipher->encrypt('01JCREDENTIAL000000000000A', 'secret');

    $cipher->decrypt('01JCREDENTIAL000000000000B', $encrypted);
})->throws(DecryptionFailedException::class);

it('fails when a byte of the secret ciphertext is flipped', function () {
    $cipher = secretCipher();
    $encrypted = $cipher->encrypt('01JCREDENTIAL000000000000A', 'secret');
    $tampered = new EncryptedSecret(flipByte($encrypted->secretCiphertext), $encrypted->dataKeyCiphertext, $encrypted->keyVersion);

    $cipher->decrypt('01JCREDENTIAL000000000000A', $tampered);
})->throws(DecryptionFailedException::class);

it('fails when a byte of the data key ciphertext is flipped', function () {
    $cipher = secretCipher();
    $encrypted = $cipher->encrypt('01JCREDENTIAL000000000000A', 'secret');
    $tampered = new EncryptedSecret($encrypted->secretCiphertext, flipByte($encrypted->dataKeyCiphertext), $encrypted->keyVersion);

    $cipher->decrypt('01JCREDENTIAL000000000000A', $tampered);
})->throws(DecryptionFailedException::class);

it('fails when the secret of one credential is combined with the data key of another', function () {
    $cipher = secretCipher();
    $a = $cipher->encrypt('01JCREDENTIAL000000000000A', 'secret a');
    $b = $cipher->encrypt('01JCREDENTIAL000000000000B', 'secret b');
    $swapped = new EncryptedSecret($a->secretCiphertext, $b->dataKeyCiphertext, $a->keyVersion);

    expect(fn () => $cipher->decrypt('01JCREDENTIAL000000000000A', $swapped))->toThrow(DecryptionFailedException::class)
        ->and(fn () => $cipher->decrypt('01JCREDENTIAL000000000000B', $swapped))->toThrow(DecryptionFailedException::class);
});

it('fails when the key version in the record is changed', function () {
    $cipher = secretCipher(2, '1:'.vaultTestKey(1));
    $encrypted = $cipher->encrypt('01JCREDENTIAL000000000000A', 'secret');
    $relabelled = new EncryptedSecret($encrypted->secretCiphertext, $encrypted->dataKeyCiphertext, 1);

    $cipher->decrypt('01JCREDENTIAL000000000000A', $relabelled);
})->throws(DecryptionFailedException::class);

it('fails with a generic message on malformed input', function (string $secretCiphertext, string $dataKeyCiphertext, int $version) {
    $cipher = secretCipher();

    try {
        $cipher->decrypt('01JCREDENTIAL000000000000A', new EncryptedSecret($secretCiphertext, $dataKeyCiphertext, $version));
        $thrown = null;
    } catch (DecryptionFailedException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(DecryptionFailedException::class)
        ->and($thrown->getMessage())->not->toContain('01JCREDENTIAL000000000000A')
        ->not->toContain($secretCiphertext === '' ? 'never-matches' : $secretCiphertext);
})->with([
    'not base64' => ['***not base64***', '***not base64***', 1],
    'empty' => ['', '', 1],
    'too short' => [base64_encode('short'), base64_encode('short'), 1],
    'unknown key version' => [base64_encode(str_repeat('a', 60)), base64_encode(str_repeat('a', 60)), 9],
]);

it('rewraps the data key with the current key and leaves the secret ciphertext alone', function () {
    $old = secretCipher(1);
    $encrypted = $old->encrypt('01JCREDENTIAL000000000000A', 'rotating secret');

    $rotated = secretCipher(2, '1:'.vaultTestKey(1));
    $rewrapped = $rotated->rewrap('01JCREDENTIAL000000000000A', $encrypted);

    expect($rewrapped->secretCiphertext)->toBe($encrypted->secretCiphertext)
        ->and($rewrapped->dataKeyCiphertext)->not->toBe($encrypted->dataKeyCiphertext)
        ->and($rewrapped->keyVersion)->toBe(2)
        ->and($rotated->decrypt('01JCREDENTIAL000000000000A', $rewrapped))->toBe('rotating secret');
});

it('cannot decrypt a rewrapped record without the new key', function () {
    $encrypted = secretCipher(1)->encrypt('01JCREDENTIAL000000000000A', 'rotating secret');
    $rewrapped = secretCipher(2, '1:'.vaultTestKey(1))->rewrap('01JCREDENTIAL000000000000A', $encrypted);

    secretCipher(1)->decrypt('01JCREDENTIAL000000000000A', $rewrapped);
})->throws(DecryptionFailedException::class);

it('refuses to rewrap a tampered record', function () {
    $cipher = secretCipher();
    $encrypted = $cipher->encrypt('01JCREDENTIAL000000000000A', 'secret');
    $tampered = new EncryptedSecret($encrypted->secretCiphertext, flipByte($encrypted->dataKeyCiphertext), $encrypted->keyVersion);

    $cipher->rewrap('01JCREDENTIAL000000000000A', $tampered);
})->throws(DecryptionFailedException::class);

it('uses one generic message for decryption failures', function () {
    $exception = new DecryptionFailedException;

    expect($exception->getMessage())->toBe('The secret could not be decrypted.');
});
