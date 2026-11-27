<?php

use App\Enums\AuditAction;
use App\Models\AuditEntry;
use App\Models\Credential;
use App\Models\User;
use App\Vault\Audit\AuditLogger;
use App\Vault\Health\AdminExistsCheck;
use App\Vault\Health\AuditChainCheck;
use App\Vault\Health\EnvFilePermissionsCheck;
use App\Vault\Health\HttpsCheck;
use App\Vault\Health\KeyVersionsCheck;
use App\Vault\Health\SecondFactorCheck;
use App\Vault\Health\SessionCheck;
use App\Vault\Health\SodiumCheck;
use App\Vault\Health\TrustedProxiesCheck;
use App\Vault\Health\VaultKeyCheck;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Health\Checks\Result;

function runCheck(string $class): Result
{
    return app($class)->run();
}

function statusOf(Result $result): string
{
    return (string) $result->status->value;
}

it('passes https only for an https app url', function () {
    config(['app.url' => 'https://vault.example.test']);
    expect(statusOf(runCheck(HttpsCheck::class)))->toBe('ok');

    config(['app.url' => 'http://vault.example.test']);
    $result = runCheck(HttpsCheck::class);

    expect(statusOf($result))->toBe('failed')->and($result->meta['fix'])->toContain('HTTPS');
});

it('passes when sodium is loaded', function () {
    expect(statusOf(runCheck(SodiumCheck::class)))->toBe('ok');
});

it('checks the vault key', function () {
    expect(statusOf(runCheck(VaultKeyCheck::class)))->toBe('ok');

    config(['vault.key' => null]);
    expect(statusOf(runCheck(VaultKeyCheck::class)))->toBe('failed');

    config(['vault.key' => 'base64:c2hvcnQ=']);
    expect(statusOf(runCheck(VaultKeyCheck::class)))->toBe('failed');

    config(['vault.key' => vaultTestKey(), 'app.key' => vaultTestKey()]);
    $result = runCheck(VaultKeyCheck::class);

    expect(statusOf($result))->toBe('failed')->and($result->getNotificationMessage())->toBe('VAULT_KEY is identical to APP_KEY.');
});

it('never puts key material in a vault key result', function () {
    config(['vault.key' => 'base64:'.base64_encode('not thirty two bytes long at all!!!!')]);
    $result = runCheck(VaultKeyCheck::class);

    expect(json_encode([$result->getNotificationMessage(), $result->getShortSummary(), $result->meta]))->not->toContain(base64_encode('not thirty two'));
});

it('checks that every credential key version resolves', function () {
    $credential = Credential::factory()->create();

    expect(statusOf(runCheck(KeyVersionsCheck::class)))->toBe('ok');

    DB::table('credentials')->where('id', $credential->id)->update(['key_version' => 9]);
    $result = runCheck(KeyVersionsCheck::class);

    expect(statusOf($result))->toBe('failed')->and($result->getNotificationMessage())->toContain('key version 9');

    config(['vault.previous_keys' => '9:'.vaultTestKey(9)]);
    expect(statusOf(runCheck(KeyVersionsCheck::class)))->toBe('ok');
});

it('warns about a previous key that no credential uses', function () {
    Credential::factory()->create();
    config(['vault.key_version' => 1, 'vault.previous_keys' => '2:'.vaultTestKey(2)]);

    $result = runCheck(KeyVersionsCheck::class);

    expect(statusOf($result))->toBe('warning')->and($result->meta['fix'])->toContain('Remove version 2');
});

it('checks the permissions of the env file', function () {
    $directory = sys_get_temp_dir().'/vault-env-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory.'/.env', 'APP_NAME=Vault');
    $this->app->useEnvironmentPath($directory);

    if (PHP_OS_FAMILY === 'Windows') {
        expect(statusOf(runCheck(EnvFilePermissionsCheck::class)))->toBe('warning');
    } else {
        chmod($directory.'/.env', 0600);
        expect(statusOf(runCheck(EnvFilePermissionsCheck::class)))->toBe('ok');

        chmod($directory.'/.env', 0644);
        expect(statusOf(runCheck(EnvFilePermissionsCheck::class)))->toBe('failed');

        chmod($directory.'/.env', 0660);
        expect(statusOf(runCheck(EnvFilePermissionsCheck::class)))->toBe('failed');

        unlink($directory.'/.env');
        expect(statusOf(runCheck(EnvFilePermissionsCheck::class)))->toBe('ok');
    }

    @unlink($directory.'/.env');
    rmdir($directory);
});

it('checks the session cookie in production only', function () {
    config(['session.secure' => false, 'session.same_site' => 'lax']);
    expect(statusOf(runCheck(SessionCheck::class)))->toBe('ok');

    $this->app->detectEnvironment(fn (): string => 'production');
    $result = runCheck(SessionCheck::class);

    expect(statusOf($result))->toBe('failed')->and($result->getNotificationMessage())->toContain('SESSION_SECURE_COOKIE')->toContain('SESSION_SAME_SITE');

    config(['session.secure' => true, 'session.same_site' => 'strict']);
    expect(statusOf(runCheck(SessionCheck::class)))->toBe('ok');

    config(['session.same_site' => 'lax']);
    expect(statusOf(runCheck(SessionCheck::class)))->toBe('failed');
});

it('checks the trusted proxies', function (string $value, string $status) {
    config(['vault.trusted_proxies' => $value]);

    expect(statusOf(runCheck(TrustedProxiesCheck::class)))->toBe($status);
})->with([
    'empty' => ['', 'ok'],
    'addresses and ranges' => ['10.0.0.1, 192.168.0.0/16', 'ok'],
    'wildcard' => ['*', 'failed'],
    'not an address' => ['proxy.example.test', 'failed'],
    'wildcard among valid entries' => ['10.0.0.1,*', 'failed'],
]);

it('warns about admins without a second factor and reports only the number', function () {
    User::factory()->withTwoFactor()->create();
    expect(statusOf(runCheck(SecondFactorCheck::class)))->toBe('ok');

    User::factory()->create(['email' => 'nofactor@example.test', 'name' => 'No Factor']);
    $result = runCheck(SecondFactorCheck::class);

    expect(statusOf($result))->toBe('warning')
        ->and($result->getNotificationMessage())->toBe('1 admin(s) have no second factor.')
        ->and(json_encode([$result->getNotificationMessage(), $result->getShortSummary(), $result->meta]))->not->toContain('nofactor@example.test')->not->toContain('No Factor');
});

it('ignores deactivated admins in the second factor check', function () {
    User::factory()->withTwoFactor()->create();
    User::factory()->create(['deactivated_at' => now()]);

    expect(statusOf(runCheck(SecondFactorCheck::class)))->toBe('ok');
});

it('checks the audit chain and reuses the outcome for an hour', function () {
    Cache::flush();
    app(AuditLogger::class)->record(AuditAction::KeyRotated, null, ['credential_count' => 1]);
    app(AuditLogger::class)->record(AuditAction::KeyRotated, null, ['credential_count' => 2]);

    expect(statusOf(runCheck(AuditChainCheck::class)))->toBe('ok');

    AuditEntry::query()->where('sequence', 2)->toBase()->update(['event' => 'project_deleted']);

    expect(statusOf(runCheck(AuditChainCheck::class)))->toBe('ok');

    AuditChainCheck::forget();
    $result = runCheck(AuditChainCheck::class);

    expect(statusOf($result))->toBe('failed')->and($result->getNotificationMessage())->toBe('The audit log chain is broken at entry 2.');
});

it('fails when no active admin exists', function () {
    expect(statusOf(runCheck(AdminExistsCheck::class)))->toBe('failed');

    User::factory()->create(['deactivated_at' => now()]);
    expect(statusOf(runCheck(AdminExistsCheck::class)))->toBe('failed');

    User::factory()->create();
    expect(statusOf(runCheck(AdminExistsCheck::class)))->toBe('ok');
});

it('gives every vault check a short summary and no secret or email', function () {
    $admin = User::factory()->create(['email' => 'secret-admin@example.test']);

    foreach ([HttpsCheck::class, SodiumCheck::class, VaultKeyCheck::class, KeyVersionsCheck::class, EnvFilePermissionsCheck::class, SessionCheck::class, TrustedProxiesCheck::class, SecondFactorCheck::class, AuditChainCheck::class, AdminExistsCheck::class] as $class) {
        $result = runCheck($class);
        $text = json_encode([$result->getNotificationMessage(), $result->getShortSummary(), $result->meta]);

        expect($result->getShortSummary())->not->toBe('')
            ->and($text)->not->toContain($admin->email)->not->toContain(config('vault.key'))->not->toContain(config('app.key'));
    }
});
