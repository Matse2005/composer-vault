<?php

use App\Exceptions\Vault\InvalidVaultKeyException;
use App\Vault\Crypto\MasterKey;

it('accepts exactly 32 bytes', function () {
    $key = new MasterKey(2, str_repeat('k', 32));

    expect($key->version)->toBe(2)
        ->and($key->bytes)->toBe(str_repeat('k', 32));
});

it('rejects a key that is not 32 bytes', function (int $length) {
    new MasterKey(1, str_repeat('k', $length));
})->with([0, 16, 31, 33, 64])->throws(InvalidVaultKeyException::class);

it('does not output the key bytes in var_dump or print_r', function () {
    $bytes = random_bytes(32);
    $key = new MasterKey(3, $bytes);

    ob_start();
    var_dump($key);
    $varDump = ob_get_clean();
    $printR = print_r($key, true);

    expect($varDump)->not->toContain($bytes)->toContain('version')
        ->and($printR)->not->toContain($bytes)->toContain('version');
});

it('cannot be serialized', function () {
    $key = new MasterKey(1, random_bytes(32));

    serialize($key);
})->throws(LogicException::class);

it('does not expose the key bytes through json', function () {
    $bytes = random_bytes(32);

    expect(json_encode(new MasterKey(1, $bytes)))->toBe('{"version":1}');
});
