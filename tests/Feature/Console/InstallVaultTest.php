<?php

use App\Models\AuditEntry;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->envDirectory = sys_get_temp_dir().'/vault-install-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->envDirectory);
    $this->envPath = $this->envDirectory.'/.env';
    file_put_contents($this->envPath, "APP_NAME=Vault\nAPP_KEY=\nVAULT_KEY=\nVAULT_KEY_VERSION=\n");

    $this->app->useEnvironmentPath($this->envDirectory);
    $this->app->loadEnvironmentFrom('.env');
    config(['vault.key' => null, 'vault.key_version' => 1]);
});

afterEach(function () {
    File::deleteDirectory($this->envDirectory);
});

it('creates the key, the first admin and the audit entry on a fresh database', function () {
    $this->artisan('vault:install')
        ->expectsQuestion('Name', 'First Admin')
        ->expectsQuestion('Email address', 'First@Example.test')
        ->expectsQuestion('Password', 'a-Long-Passw0rd!')
        ->expectsQuestion('Confirm password', 'a-Long-Passw0rd!')
        ->expectsOutputToContain('Back up VAULT_KEY separately from your database')
        ->expectsOutputToContain('vault:doctor')
        ->assertSuccessful();

    $env = file_get_contents($this->envPath);
    $user = User::query()->sole();

    expect($env)->toMatch('/^VAULT_KEY=base64:[A-Za-z0-9+\/]{43}=$/m')
        ->toContain("VAULT_KEY_VERSION=1\n")
        ->and($user->email)->toBe('first@example.test')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->role->value)->toBe('owner')
        ->and(password_verify('a-Long-Passw0rd!', $user->password))->toBeTrue();

    $entry = AuditEntry::query()->where('event', 'vault_installed')->sole();

    expect($entry->properties['admin_id'])->toBe($user->id)
        ->and(json_encode($entry->properties))->not->toContain('first@example.test');
});

it('generates the application key when it is missing', function () {
    config(['app.key' => '']);

    $this->artisan('vault:install')
        ->expectsQuestion('Name', 'First Admin')
        ->expectsQuestion('Email address', 'first@example.test')
        ->expectsQuestion('Password', 'a-Long-Passw0rd!')
        ->expectsQuestion('Confirm password', 'a-Long-Passw0rd!')
        ->assertSuccessful();

    expect(file_get_contents($this->envPath))->toMatch('/^APP_KEY=base64:.+$/m');
});

it('refuses to run twice and changes nothing', function () {
    User::factory()->create();
    $before = file_get_contents($this->envPath);

    $this->artisan('vault:install')->expectsOutputToContain('Composer Vault is already installed.')->assertFailed();
    $this->artisan('vault:install', ['--no-interaction' => true, '--name' => 'X', '--email' => 'x@example.test'])->assertFailed();

    expect(file_get_contents($this->envPath))->toBe($before)
        ->and(User::query()->count())->toBe(1)
        ->and(Invitation::query()->count())->toBe(0)
        ->and(AuditEntry::query()->count())->toBe(0);
});

it('keeps a key that is already configured', function () {
    config(['vault.key' => vaultTestKey()]);
    $before = file_get_contents($this->envPath);

    $this->artisan('vault:install', ['--no-interaction' => true, '--name' => 'First Admin', '--email' => 'first@example.test'])->assertSuccessful();

    expect(file_get_contents($this->envPath))->toBe($before);
});

it('stops on a configured key that is not valid', function () {
    config(['vault.key' => 'base64:c2hvcnQ=']);

    $this->artisan('vault:install', ['--no-interaction' => true, '--name' => 'First Admin', '--email' => 'first@example.test'])->assertFailed();

    expect(User::query()->count())->toBe(0);
});

it('invites the first admin without interaction and creates no user', function () {
    config(['mail.default' => 'log']);

    $this->artisan('vault:install', ['--no-interaction' => true, '--name' => 'First Admin', '--email' => 'first@example.test'])
        ->expectsOutputToContain(url('/invitations/'))
        ->assertSuccessful();

    $invitation = Invitation::query()->sole();

    expect(User::query()->count())->toBe(0)
        ->and($invitation->email)->toBe('first@example.test')
        ->and($invitation->invited_by)->toBeNull()
        ->and($invitation->role->value)->toBe('owner');

    $entry = AuditEntry::query()->where('event', 'vault_installed')->sole();

    expect($entry->properties['invitation_id'])->toBe($invitation->id)
        ->and(json_encode($entry->properties))->not->toContain('first@example.test');
});

it('needs a name and a valid email without interaction', function (array $options) {
    $this->artisan('vault:install', ['--no-interaction' => true, ...$options])->assertFailed();

    expect(Invitation::query()->count())->toBe(0)->and(file_get_contents($this->envPath))->toContain("VAULT_KEY=\n");
})->with([
    'nothing' => [[]],
    'no email' => [['--name' => 'First Admin']],
    'no name' => [['--email' => 'first@example.test']],
    'invalid email' => [['--name' => 'First Admin', '--email' => 'not-an-email']],
]);

it('prints the key lines and stops before migrating when the env file cannot be written', function () {
    unlink($this->envPath);
    $migrated = false;
    Event::listen(MigrationsStarted::class, function () use (&$migrated) {
        $migrated = true;
    });

    $this->artisan('vault:install', ['--no-interaction' => true, '--name' => 'First Admin', '--email' => 'first@example.test'])
        ->expectsOutputToContain('VAULT_KEY=base64:')
        ->expectsOutputToContain('VAULT_KEY_VERSION=1')
        ->assertFailed();

    expect($migrated)->toBeFalse()->and(Invitation::query()->count())->toBe(0);
});

it('does not overwrite a key that is in the env file but not loaded', function () {
    file_put_contents($this->envPath, "VAULT_KEY=base64:existing-key-value\n");

    $this->artisan('vault:install', ['--no-interaction' => true, '--name' => 'First Admin', '--email' => 'first@example.test'])->assertFailed();

    expect(file_get_contents($this->envPath))->toBe("VAULT_KEY=base64:existing-key-value\n");
});

it('never prints the password', function () {
    $this->artisan('vault:install')
        ->expectsQuestion('Name', 'First Admin')
        ->expectsQuestion('Email address', 'first@example.test')
        ->expectsQuestion('Password', 'a-Long-Passw0rd!')
        ->expectsQuestion('Confirm password', 'a-Long-Passw0rd!')
        ->doesntExpectOutputToContain('a-Long-Passw0rd!')
        ->assertSuccessful();
});
