<?php

use App\Exceptions\Vault\InvalidVaultKeyException;
use App\Exceptions\Vault\UnknownKeyVersionException;
use App\Vault\Crypto\EnvKeyProvider;
use App\Vault\Crypto\KeyProvider;
use Illuminate\Config\Repository;

function envKeyProvider(array $vaultConfig): EnvKeyProvider
{
    return new EnvKeyProvider(new Repository(['vault' => $vaultConfig]));
}

it('loads the current key with its version', function () {
    $provider = envKeyProvider(['key' => vaultTestKey(3), 'key_version' => 3, 'previous_keys' => '']);

    $key = $provider->current();

    expect($key->version)->toBe(3)
        ->and($key->bytes)->toBe(base64_decode(substr(vaultTestKey(3), 7), true));
});

it('defaults the current version to 1', function () {
    $provider = envKeyProvider(['key' => vaultTestKey(), 'previous_keys' => '']);

    expect($provider->current()->version)->toBe(1);
});

it('rejects an invalid current key without leaking key material', function (?string $key) {
    $provider = envKeyProvider(['key' => $key, 'key_version' => 1, 'previous_keys' => '']);

    $thrown = null;

    try {
        $provider->current();
    } catch (InvalidVaultKeyException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(InvalidVaultKeyException::class)
        ->and($thrown->getMessage())->not->toContain((string) $key === '' ? 'never-matches' : $key);
})->with([
    'missing' => [null],
    'empty' => [''],
    'no base64 prefix' => [fn () => substr(vaultTestKey(), 7)],
    'invalid base64' => ['base64:!!!not*valid*base64!!!'],
    'too short' => ['base64:'.base64_encode(str_repeat('a', 31))],
    'too long' => ['base64:'.base64_encode(str_repeat('a', 33))],
]);

it('finds the current and the previous keys by version', function () {
    $provider = envKeyProvider([
        'key' => vaultTestKey(3),
        'key_version' => 3,
        'previous_keys' => '1:'.vaultTestKey(1).', 2:'.vaultTestKey(2),
    ]);

    expect($provider->forVersion(3)->bytes)->toBe($provider->current()->bytes)
        ->and($provider->forVersion(2)->version)->toBe(2)
        ->and($provider->forVersion(2)->bytes)->toBe(base64_decode(substr(vaultTestKey(2), 7), true))
        ->and($provider->forVersion(1)->bytes)->toBe(base64_decode(substr(vaultTestKey(1), 7), true));
});

it('throws on an unknown key version', function () {
    $provider = envKeyProvider(['key' => vaultTestKey(), 'key_version' => 1, 'previous_keys' => '']);

    $provider->forVersion(7);
})->throws(UnknownKeyVersionException::class);

it('rejects malformed previous keys without leaking key material', function (string $previousKeys) {
    $provider = envKeyProvider(['key' => vaultTestKey(3), 'key_version' => 3, 'previous_keys' => $previousKeys]);

    $thrown = null;

    try {
        $provider->forVersion(1);
    } catch (InvalidVaultKeyException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(InvalidVaultKeyException::class)
        ->and($thrown->getMessage())->not->toContain($previousKeys)
        ->not->toContain(substr(vaultTestKey(1), 7));
})->with([
    'no version' => [fn () => vaultTestKey(1)],
    'version not a number' => [fn () => 'x:'.vaultTestKey(1)],
    'version zero' => [fn () => '0:'.vaultTestKey(1)],
    'invalid key' => ['1:base64:AAAA'],
    'same version as the current key' => [fn () => '3:'.vaultTestKey(1)],
    'duplicate version' => [fn () => '1:'.vaultTestKey(1).',1:'.vaultTestKey(2)],
]);

it('is bound as the key provider singleton', function () {
    config(['vault.key' => vaultTestKey()]);

    expect(app(KeyProvider::class))->toBeInstanceOf(EnvKeyProvider::class)
        ->and(app(KeyProvider::class))->toBe(app(KeyProvider::class))
        ->and(app(KeyProvider::class)->current()->version)->toBe(1);
});
