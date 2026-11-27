<?php

use App\Exceptions\Vault\UnknownKeyVersionException;
use App\Vault\Crypto\EnvKeyProvider;
use App\Vault\Crypto\MasterKey;
use App\Vault\Crypto\RotatingKeyProvider;
use Illuminate\Config\Repository;

function rotatingProvider(): RotatingKeyProvider
{
    $inner = new EnvKeyProvider(new Repository(['vault' => [
        'key' => vaultTestKey(1),
        'key_version' => 1,
        'previous_keys' => '',
    ]]));

    return new RotatingKeyProvider(new MasterKey(2, str_repeat('n', 32)), $inner);
}

it('returns the new key as current', function () {
    expect(rotatingProvider()->current()->version)->toBe(2)
        ->and(rotatingProvider()->current()->bytes)->toBe(str_repeat('n', 32));
});

it('still resolves the old key by version and the new key by its version', function () {
    $provider = rotatingProvider();

    expect($provider->forVersion(1)->bytes)->toBe(base64_decode(substr(vaultTestKey(1), 7), true))
        ->and($provider->forVersion(2)->bytes)->toBe(str_repeat('n', 32));
});

it('throws on a version that neither key has', function () {
    rotatingProvider()->forVersion(5);
})->throws(UnknownKeyVersionException::class);
