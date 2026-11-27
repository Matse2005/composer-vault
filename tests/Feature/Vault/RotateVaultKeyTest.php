<?php

use App\Models\Credential;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Apply the env changes that the command prints, as an admin would after a rotation.
 */
function applyRotatedEnv(string $newKey, int $newVersion, string $previousKeys): void
{
    config([
        'vault.key' => $newKey,
        'vault.key_version' => $newVersion,
        'vault.previous_keys' => $previousKeys,
    ]);
}

/**
 * @return array<string, array<string, mixed>>
 */
function credentialRowSnapshot(): array
{
    return DB::table('credentials')->orderBy('id')->get()->mapWithKeys(fn ($row) => [$row->id => (array) $row])->all();
}

it('rotates every data key and keeps every secret readable', function () {
    $credentials = collect(['one', 'two', 'three'])->map(
        fn (string $name) => Credential::factory()->forHost("{$name}.example.com")->withSecret("secret-{$name}")->create()
    );
    $before = credentialRowSnapshot();

    $this->artisan('vault:rotate-key', ['--key' => vaultTestKey(2)])->assertSuccessful();

    applyRotatedEnv(vaultTestKey(2), 2, '1:'.vaultTestKey(1));

    foreach ($credentials as $credential) {
        $fresh = $credential->fresh();

        expect($fresh->revealSecret())->toBe('secret-'.explode('.', $credential->host)[0])
            ->and($fresh->key_version)->toBe(2)
            ->and($fresh->secret_ciphertext)->toBe($before[$credential->id]['secret_ciphertext'])
            ->and($fresh->data_key_ciphertext)->not->toBe($before[$credential->id]['data_key_ciphertext'])
            ->and($fresh->updated_at->toDateTimeString())->toBe($before[$credential->id]['updated_at'])
            ->and($fresh->secret_changed_at->toDateTimeString())->toBe($before[$credential->id]['secret_changed_at']);
    }

    expect(DB::table('credentials')->where('key_version', '!=', 2)->count())->toBe(0);
});

it('generates a new key when none is given and prints the env changes once', function () {
    $credential = Credential::factory()->withSecret('printed-secret-marker')->create();

    expect(Artisan::call('vault:rotate-key'))->toBe(0);
    $output = Artisan::output();

    preg_match_all('/VAULT_KEY=(base64:[A-Za-z0-9+\/]+={0,2})/', $output, $matches);

    expect($matches[1])->toHaveCount(1)
        ->and(substr_count($output, $matches[1][0]))->toBe(1)
        ->and($matches[1][0])->not->toBe(vaultTestKey(1))
        ->and($output)->toContain('VAULT_KEY_VERSION=2')
        ->toContain('VAULT_PREVIOUS_KEYS=1:'.vaultTestKey(1))
        ->not->toContain('printed-secret-marker')
        ->not->toContain($credential->secret_ciphertext);

    applyRotatedEnv($matches[1][0], 2, '1:'.vaultTestKey(1));

    expect($credential->fresh()->revealSecret())->toBe('printed-secret-marker');
});

it('adds the old key to the existing previous keys and raises the version again', function () {
    $credential = Credential::factory()->withSecret('chained secret')->create();

    $this->artisan('vault:rotate-key', ['--key' => vaultTestKey(2)])->assertSuccessful();
    applyRotatedEnv(vaultTestKey(2), 2, '1:'.vaultTestKey(1));

    Artisan::call('vault:rotate-key', ['--key' => vaultTestKey(3)]);

    expect(Artisan::output())->toContain('VAULT_KEY_VERSION=3')
        ->toContain('VAULT_PREVIOUS_KEYS=1:'.vaultTestKey(1).',2:'.vaultTestKey(2));

    applyRotatedEnv(vaultTestKey(3), 3, '1:'.vaultTestKey(1).',2:'.vaultTestKey(2));

    expect($credential->fresh()->revealSecret())->toBe('chained secret')
        ->and($credential->fresh()->key_version)->toBe(3);
});

it('aborts without changing anything when one row is corrupted', function () {
    $good = Credential::factory()->withSecret('good secret')->create();
    $bad = Credential::factory()->withSecret('bad secret')->create();
    DB::table('credentials')->where('id', $bad->id)->update(['data_key_ciphertext' => base64_encode('corrupted')]);
    $before = credentialRowSnapshot();

    expect(Artisan::call('vault:rotate-key', ['--key' => vaultTestKey(2)]))->toBe(1);

    $output = Artisan::output();

    expect(credentialRowSnapshot())->toEqual($before)
        ->and($output)->toContain('1 credential')
        ->toContain($bad->id)
        ->not->toContain($good->id)
        ->not->toContain('good secret')
        ->not->toContain('bad secret')
        ->not->toContain('VAULT_KEY=')
        ->and($good->fresh()->revealSecret())->toBe('good secret');
});

it('rejects an invalid new key without changing anything', function (string $key) {
    Credential::factory()->create();
    $before = credentialRowSnapshot();

    expect(Artisan::call('vault:rotate-key', ['--key' => $key]))->toBe(1);

    expect(credentialRowSnapshot())->toEqual($before)
        ->and(Artisan::output())->not->toContain($key);
})->with([
    'no prefix' => ['not-a-key-at-all-123456789012345'],
    'too short' => ['base64:'.'QUJD'],
    'invalid base64' => ['base64:!!!***'],
]);

it('works without any credentials', function () {
    expect(Artisan::call('vault:rotate-key', ['--key' => vaultTestKey(2)]))->toBe(0)
        ->and(Artisan::output())->toContain('VAULT_KEY_VERSION=2');
});

it('does not rotate in production without confirmation', function () {
    Credential::factory()->create();
    $before = credentialRowSnapshot();
    $this->app['env'] = 'production';

    expect(Artisan::call('vault:rotate-key', ['--key' => vaultTestKey(2), '--no-interaction' => true]))->toBe(1);

    expect(credentialRowSnapshot())->toEqual($before)
        ->and(Artisan::output())->not->toContain('VAULT_KEY=');
});

it('skips the confirmation in production with --force', function () {
    Credential::factory()->create();
    $this->app['env'] = 'production';

    $this->artisan('vault:rotate-key', ['--key' => vaultTestKey(2), '--force' => true])->assertSuccessful();

    expect(DB::table('credentials')->where('key_version', 2)->count())->toBe(1);
});
