<?php

use App\Enums\CredentialType;
use App\Vault\Audit\AuditChanges;
use Carbon\CarbonImmutable;

it('masks the values of secret keys', function (string $key) {
    expect(AuditChanges::sanitize([$key => 'very-secret-value']))->toBe([$key => 'changed']);
})->with(['secret', 'password', 'plain_token', 'notes']);

it('removes sensitive keys', function (string $key) {
    expect(AuditChanges::sanitize(['name' => 'Shop', $key => 'sensitive-value']))->toBe(['name' => 'Shop']);
})->with([
    'secret_ciphertext',
    'data_key_ciphertext',
    'key_version',
    'token_hash',
    'two_factor_secret',
    'two_factor_recovery_codes',
    'remember_token',
    'credential',
]);

it('sanitizes nested arrays and lists', function () {
    $result = AuditChanges::sanitize([
        'before' => ['name' => 'Old', 'password' => 'old-password', 'token_hash' => 'abc'],
        'rows' => [['secret' => 'x', 'host' => 'a.example.com'], ['credential' => 'y', 'host' => 'b.example.com']],
    ]);

    expect($result)->toBe([
        'before' => ['name' => 'Old', 'password' => 'changed'],
        'rows' => [['secret' => 'changed', 'host' => 'a.example.com'], ['host' => 'b.example.com']],
    ]);
});

it('matches keys regardless of case', function () {
    expect(AuditChanges::sanitize(['Secret' => 'x', 'TOKEN_HASH' => 'y', 'name' => 'Shop']))
        ->toBe(['Secret' => 'changed', 'name' => 'Shop']);
});

it('converts dates to iso 8601 utc strings', function () {
    $date = CarbonImmutable::parse('2026-11-27 15:30:45', 'Europe/Brussels');

    expect(AuditChanges::sanitize(['expires_at' => $date]))->toBe(['expires_at' => '2026-11-27T14:30:45Z']);
});

it('converts enums to their values', function () {
    expect(AuditChanges::sanitize(['type' => CredentialType::GithubOauth]))->toBe(['type' => 'github-oauth']);
});

it('converts floats to strings', function () {
    expect(AuditChanges::sanitize(['ratio' => 0.5]))->toBe(['ratio' => '0.5']);
});

it('keeps strings, integers, booleans and null', function () {
    expect(AuditChanges::sanitize(['a' => 'text', 'b' => 3, 'c' => true, 'd' => null]))
        ->toBe(['a' => 'text', 'b' => 3, 'c' => true, 'd' => null]);
});

it('rejects other objects', function () {
    AuditChanges::sanitize(['model' => new stdClass]);
})->throws(InvalidArgumentException::class);
