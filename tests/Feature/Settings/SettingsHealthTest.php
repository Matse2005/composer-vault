<?php

use App\Models\User;
use App\Vault\Crypto\KeyProvider;
use App\Vault\Health\SettingsCheck;
use App\Vault\Settings\VaultSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Health\Facades\Health;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->check = fn (): string => app(SettingsCheck::class)->run()->status->value;
});

it('is ok with valid stored settings and with none', function () {
    expect(($this->check)())->toBe('ok');

    app(VaultSettings::class)->update(['api.rate_limit_per_minute' => 80], $this->owner);
    app(VaultSettings::class)->setSecret('mail.password', 'a-password', $this->owner);

    expect(($this->check)())->toBe('ok');
});

it('fails when a stored value no longer passes its rules, naming the setting and not the value', function () {
    DB::table('vault_settings')->insert(['key' => 'retention.audit_log_days', 'value' => json_encode(5)]);
    app()->forgetInstance(VaultSettings::class);

    $result = app(SettingsCheck::class)->run();

    expect($result->status->value)->toBe('failed')->and($result->notificationMessage)->toContain('retention.audit_log_days')->not->toContain('5 days');
});

it('fails when a stored secret cannot be decrypted', function () {
    app(VaultSettings::class)->setSecret('mail.password', 'a-password', $this->owner);
    config(['vault.key' => vaultTestKey(7), 'vault.key_version' => 7, 'vault.previous_keys' => '']);
    app()->forgetInstance(VaultSettings::class);
    app()->forgetInstance(KeyProvider::class);

    $result = app(SettingsCheck::class)->run();

    expect($result->status->value)->toBe('failed')->and($result->notificationMessage)->toContain('mail.password');
});

it('warns when a setting is set in the environment and in the dashboard', function () {
    app(VaultSettings::class)->update(['api.rate_limit_per_minute' => 80], $this->owner);
    config(['vault.api.rate_limit_per_minute' => 30]);

    $result = app(SettingsCheck::class)->run();

    expect($result->status->value)->toBe('warning')->and($result->notificationMessage)->toContain('api.rate_limit_per_minute');
});

it('is part of the registered checks', function () {
    expect(collect(Health::registeredChecks())->map(fn ($check) => $check::class)->all())->toContain(SettingsCheck::class);
});

it('adds the source of each security setting to the doctor json and the compliance report', function () {
    app(VaultSettings::class)->update(['retention.audit_log_days' => 200], $this->owner, ['retention.audit_log_days' => 'DELETE']);
    config(['vault.admin.allowed_ips' => '10.0.0.0/8']);

    Artisan::call('vault:doctor', ['--json' => true]);
    $doctor = json_decode(Artisan::output(), true);

    expect($doctor['settings'])->toHaveKeys(['sso.enabled', 'retention.audit_log_days', 'admin.allowed_ips'])
        ->and($doctor['settings']['retention.audit_log_days'])->toBe('dashboard')
        ->and($doctor['settings']['admin.allowed_ips'])->toBe('environment')
        ->and($doctor['settings']['sso.enabled'])->toBe('default')
        ->and($doctor['settings'])->not->toHaveKey('mail.password')->not->toHaveKey('api.rate_limit_per_minute');

    Artisan::call('vault:compliance-report', ['--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($report['settings'])->toBe($doctor['settings']);

    Artisan::call('vault:compliance-report');

    expect(Artisan::output())->toContain('Where the security-relevant settings come from')->toContain('retention.audit_log_days');
});

it('shows the sources on the compliance report page', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create());

    Livewire\Livewire::test('pages::compliance.report')->assertSee('Where the settings come from')->assertSee('retention.audit_log_days')->assertSee('default');
});
