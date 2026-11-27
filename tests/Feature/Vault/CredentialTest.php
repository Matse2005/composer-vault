<?php

use App\Enums\CredentialType;
use App\Models\Credential;
use Carbon\CarbonInterface;

it('casts the type and normalizes the host', function () {
    $credential = Credential::factory()->create([
        'type' => 'github-oauth',
        'host' => 'https://GitHub.com/',
    ]);

    $credential->refresh();

    expect($credential->type)->toBe(CredentialType::GithubOauth)
        ->and($credential->host)->toBe('github.com')
        ->and($credential->getKey())->toBeString()->toHaveLength(26)
        ->and($credential->secret_changed_at)->toBeInstanceOf(CarbonInterface::class);
});

it('rejects an invalid host when assigned', function () {
    Credential::factory()->create(['host' => 'repo.magento.com:443']);
})->throws(InvalidArgumentException::class);

it('does not mass assign the crypto columns', function () {
    $credential = Credential::factory()->create();
    $original = $credential->only(['secret_ciphertext', 'data_key_ciphertext', 'key_version', 'secret_changed_at']);

    $credential->fill([
        'name' => 'Renamed',
        'secret_ciphertext' => 'injected-ciphertext',
        'data_key_ciphertext' => 'injected-data-key',
        'key_version' => 9,
        'secret_changed_at' => now()->addYear(),
    ])->save();

    expect($credential->refresh()->name)->toBe('Renamed')
        ->and($credential->only(['secret_ciphertext', 'data_key_ciphertext', 'key_version', 'secret_changed_at']))
        ->toEqual($original);
});

it('hides the crypto columns from arrays and json', function () {
    $credential = Credential::factory()->create();

    $array = $credential->toArray();

    expect($array)->not->toHaveKeys(['secret_ciphertext', 'data_key_ciphertext', 'key_version'])
        ->and($credential->toJson())->not->toContain('secret_ciphertext')
        ->not->toContain('data_key_ciphertext');
});

it('sets the type with the matching username through factory states', function () {
    $bearer = Credential::factory()->ofType(CredentialType::Bearer)->create();
    $basic = Credential::factory()->ofType(CredentialType::HttpBasic)->create();

    expect($bearer->username)->toBeNull()
        ->and($basic->username)->not->toBeNull();
});
