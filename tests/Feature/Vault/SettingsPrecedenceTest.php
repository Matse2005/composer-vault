<?php

use App\Models\User;
use App\Vault\Settings\ApplyVaultSettings;
use App\Vault\Settings\VaultSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->settings = fn (): VaultSettings => app(VaultSettings::class);
});

it('resolves a setting from the environment, then the dashboard, then the default', function () {
    expect(($this->settings)()->apiRateLimitPerMinute())->toBe(60);

    ($this->settings)()->update(['api.rate_limit_per_minute' => 120], $this->owner);
    expect(($this->settings)()->apiRateLimitPerMinute())->toBe(120)->and(($this->settings)()->isFromEnvironment('api.rate_limit_per_minute'))->toBeFalse();

    config(['vault.api.rate_limit_per_minute' => 30]);
    expect(($this->settings)()->apiRateLimitPerMinute())->toBe(30)->and(($this->settings)()->isFromEnvironment('api.rate_limit_per_minute'))->toBeTrue();

    config(['vault.api.rate_limit_per_minute' => null]);
    expect(($this->settings)()->apiRateLimitPerMinute())->toBe(120);
});

it('validates the new settings', function (string $key, mixed $value) {
    expect(fn () => ($this->settings)()->update([$key => $value], $this->owner))->toThrow(ValidationException::class);
})->with([
    'rate limit zero' => ['api.rate_limit_per_minute', 0],
    'failed attempts too high' => ['api.failed_attempts_per_minute', 100000],
    'ip allowlist entry' => ['admin.allowed_ips', '10.0.0.1, not-an-ip'],
    'idle minutes too low' => ['security.session_idle_minutes', 1],
    'invitation hours zero' => ['invitations.expire_hours', 0],
    'mail port' => ['mail.port', 70000],
    'mail encryption' => ['mail.encryption', 'sendmail'],
    'mail from' => ['mail.from_address', 'nope'],
    'alert threshold' => ['alerts.failed_attempts_threshold', 1],
]);

it('applies the session lifetime and the mail transport from the dashboard before they are used', function () {
    config(['mail.default' => 'smtp', 'vault.env.session_lifetime' => null, 'vault.env.mail_host' => null, 'vault.env.mail_port' => null, 'vault.env.mail_username' => null, 'vault.env.mail_password' => null, 'vault.env.mail_encryption' => null, 'vault.env.mail_from_address' => null, 'vault.env.mail_from_name' => null]);
    ($this->settings)()->update([
        'security.session_idle_minutes' => 15,
        'mail.host' => 'smtp.example.test',
        'mail.port' => 465,
        'mail.encryption' => 'ssl',
        'mail.username' => 'mailer',
        'mail.from_address' => 'vault@example.test',
        'mail.from_name' => 'Vault',
    ], $this->owner);
    ($this->settings)()->setSecret('mail.password', 'smtp-password-value', $this->owner);

    app(ApplyVaultSettings::class)->handle();

    expect(config('session.lifetime'))->toBe(15)
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.example.test')
        ->and(config('mail.mailers.smtp.port'))->toBe(465)
        ->and(config('mail.mailers.smtp.username'))->toBe('mailer')
        ->and(config('mail.mailers.smtp.password'))->toBe('smtp-password-value')
        ->and(config('mail.mailers.smtp.scheme'))->toBe('smtps')
        ->and(config('mail.from.address'))->toBe('vault@example.test')
        ->and(config('mail.from.name'))->toBe('Vault');
});

it('leaves the mail transport alone when the mailer is not smtp', function () {
    config(['mail.default' => 'log', 'mail.mailers.smtp.host' => 'untouched.example.test']);
    ($this->settings)()->update(['mail.host' => 'smtp.example.test'], $this->owner);

    app(ApplyVaultSettings::class)->handle();

    expect(config('mail.mailers.smtp.host'))->toBe('untouched.example.test');
});

it('lets an environment value win over a dashboard value for the applied settings', function () {
    ($this->settings)()->update(['security.session_idle_minutes' => 15], $this->owner);
    config(['vault.env.session_lifetime' => 45]);

    app(ApplyVaultSettings::class)->handle();

    expect(config('session.lifetime'))->toBe(45);
});

it('does not fail when the settings table does not exist yet', function () {
    Schema::drop('vault_settings');
    app()->forgetInstance(VaultSettings::class);

    app(ApplyVaultSettings::class)->handle();

    expect(app(VaultSettings::class)->apiRateLimitPerMinute())->toBe(60);
});

it('encrypts the mail password and never audits it', function () {
    ($this->settings)()->setSecret('mail.password', 'smtp-password-value', $this->owner);

    expect((string) DB::table('vault_settings')->where('key', 'mail.password')->value('value'))->not->toContain('smtp-password-value')
        ->and(json_encode(DB::table('activity_log')->get()))->not->toContain('smtp-password-value');
});

it('uses the session lifetime of the dashboard for the idle timeout of a real session', function () {
    config(['vault.env.session_lifetime' => null]);
    ($this->settings)()->update(['security.session_idle_minutes' => 7], $this->owner);

    app(ApplyVaultSettings::class)->handle();

    expect((int) config('session.lifetime'))->toBe(7);
});
