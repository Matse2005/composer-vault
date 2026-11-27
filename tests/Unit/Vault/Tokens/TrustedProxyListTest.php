<?php

use App\Exceptions\Vault\InvalidTrustedProxyException;
use App\Vault\Tokens\TrustedProxyList;

it('parses a comma separated list', function (?string $configured, array $expected) {
    expect(TrustedProxyList::parse($configured))->toBe($expected);
})->with([
    'null' => [null, []],
    'empty' => ['', []],
    'blank' => ['   ', []],
    'one address' => ['10.0.0.1', ['10.0.0.1']],
    'addresses and ranges with spaces' => [' 10.0.0.1 , 172.16.0.0/12,2001:db8::/32 ', ['10.0.0.1', '172.16.0.0/12', '2001:db8::/32']],
    'trailing comma' => ['10.0.0.1,', ['10.0.0.1']],
]);

it('rejects wildcards and invalid entries with a clear message', function (string $configured) {
    $thrown = null;

    try {
        TrustedProxyList::parse($configured);
    } catch (InvalidTrustedProxyException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(InvalidTrustedProxyException::class)
        ->and($thrown->getMessage())->toContain('TRUSTED_PROXIES');
})->with([
    'wildcard' => '*',
    'double wildcard' => '**',
    'wildcard in a list' => '10.0.0.1,*',
    'remote address keyword' => 'REMOTE_ADDR',
    'hostname' => 'proxy.example.com',
    'prefix too long' => '10.0.0.0/33',
]);
