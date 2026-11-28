<?php

use App\Actions\Vault\SetLockdown;
use App\Exceptions\Vault\LockoutGuardException;
use App\Models\AuditEntry;
use App\Models\User;
use App\Vault\Authorization\Ability;
use App\Vault\Compliance\SecurityAlertNotification;
use App\Vault\Settings\VaultSettings;
use App\Vault\Sso\SsoConfig;
use App\Vault\Sso\SsoTestSignIn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\FakeOidcProvider;

beforeEach(function () {
    $this->owner = User::factory()->withTwoFactor()->create(['email' => 'owner@example.test']);
    $this->actingAs($this->owner);
    $this->settings = fn (): VaultSettings => app(VaultSettings::class);
});

it('refuses an admin allowlist without your own address until you confirm', function () {
    expect(fn () => ($this->settings)()->update(['admin.allowed_ips' => '10.0.0.0/8'], $this->owner))
        ->toThrow(LockoutGuardException::class, '127.0.0.1');

    expect(($this->settings)()->get('admin.allowed_ips'))->toBeNull();

    ($this->settings)()->update(['admin.allowed_ips' => '10.0.0.0/8'], $this->owner, ['admin.allowed_ips' => true]);

    expect(($this->settings)()->get('admin.allowed_ips'))->toBe('10.0.0.0/8');
});

it('accepts an allowlist that holds your address, a cidr that covers it, and clearing the list', function () {
    ($this->settings)()->update(['admin.allowed_ips' => '10.0.0.1, 127.0.0.0/8'], $this->owner);
    expect(($this->settings)()->get('admin.allowed_ips'))->toBe('10.0.0.1, 127.0.0.0/8');

    ($this->settings)()->update(['admin.allowed_ips' => ''], $this->owner);
    expect(($this->settings)()->get('admin.allowed_ips'))->toBeNull();
});

it('warns on the security page and saves after the confirmation', function () {
    $component = Livewire::test('pages::settings.access')->set('adminAllowedIps', '10.0.0.0/8')->call('save');

    expect($component->get('lockoutWarning'))->toContain('127.0.0.1');
    expect(($this->settings)()->get('admin.allowed_ips'))->toBeNull();

    $component->call('saveConfirmed')->assertHasNoErrors();

    expect(($this->settings)()->get('admin.allowed_ips'))->toBe('10.0.0.0/8');
});

it('checks discovery when the issuer changes and stores nothing when it fails', function () {
    Http::fake(['*' => Http::response('nope', 500)]);

    expect(fn () => ($this->settings)()->update(['sso.issuer' => 'https://down.example.test', 'sso.client_id' => 'abc'], $this->owner))
        ->toThrow(ValidationException::class, 'discovery');

    expect(DB::table('vault_settings')->whereIn('key', ['sso.issuer', 'sso.client_id'])->count())->toBe(0);
});

it('saves the issuer when discovery works', function () {
    (new FakeOidcProvider)->fake();
    ($this->settings)()->update(['sso.issuer' => FakeOidcProvider::ISSUER], $this->owner);

    expect(($this->settings)()->get('sso.issuer'))->toBe(FakeOidcProvider::ISSUER);
});

it('refuses to turn enforcement on without a test sign-in, then without a break-glass owner', function () {
    $this->idp = (new FakeOidcProvider)->install();
    $enforce = fn () => ($this->settings)()->update(['sso.enforce' => true], $this->owner);

    expect($enforce)->toThrow(ValidationException::class, 'Test the sign-in first');

    session([SsoTestSignIn::PASSED_KEY => ['user' => $this->owner->id, 'at' => now()->getTimestamp()]]);

    expect($enforce)->toThrow(ValidationException::class, 'break-glass');

    $this->owner->forceFill(['is_break_glass' => true])->save();
    $enforce();

    expect(DB::table('vault_settings')->where('key', 'sso.enforce')->exists())->toBeTrue();
    config(['vault.sso.enabled' => true]);
    expect(app(SsoConfig::class)->enforced())->toBeTrue();
});

it('refuses enforcement when the test pass is old or belongs to someone else, or the break-glass owner has no second factor', function () {
    (new FakeOidcProvider)->install();
    $this->owner->forceFill(['is_break_glass' => true])->save();

    session([SsoTestSignIn::PASSED_KEY => ['user' => $this->owner->id, 'at' => now()->subMinutes(11)->getTimestamp()]]);
    expect(fn () => ($this->settings)()->update(['sso.enforce' => true], $this->owner))->toThrow(ValidationException::class, 'Test the sign-in first');

    session([SsoTestSignIn::PASSED_KEY => ['user' => 999, 'at' => now()->getTimestamp()]]);
    expect(fn () => ($this->settings)()->update(['sso.enforce' => true], $this->owner))->toThrow(ValidationException::class, 'Test the sign-in first');

    session([SsoTestSignIn::PASSED_KEY => ['user' => $this->owner->id, 'at' => now()->getTimestamp()]]);
    $this->owner->forceFill(['two_factor_confirmed_at' => null])->save();
    expect(fn () => ($this->settings)()->update(['sso.enforce' => true], $this->owner))->toThrow(ValidationException::class, 'break-glass');
});

it('passes the test sign-in for the same admin and refuses another identity', function () {
    $this->idp = (new FakeOidcProvider)->install();
    $this->owner->forceFill(['is_break_glass' => true])->save();

    $this->get(route('sso.test'))->assertRedirect();
    $started = startSsoFromCurrentRedirect();
    $this->idp->nextIdToken = $this->idp->idToken($started['nonce'], ['email' => 'owner@example.test']);

    $this->get(route('sso.callback', ['code' => 'c', 'state' => $started['state']]))->assertRedirect(route('settings.sso'))->assertSessionHas('sso_test_passed');

    expect(auth()->id())->toBe($this->owner->id)->and(app(SsoTestSignIn::class)->passedRecentlyBy($this->owner))->toBeTrue();

    ($this->settings)()->update(['sso.enforce' => true], $this->owner);
    expect(DB::table('vault_settings')->where('key', 'sso.enforce')->exists())->toBeTrue();

    session()->forget(SsoTestSignIn::PASSED_KEY);
    $this->get(route('sso.test'))->assertRedirect();
    $again = startSsoFromCurrentRedirect();
    $this->idp->nextIdToken = $this->idp->idToken($again['nonce'], ['email' => 'someone.else@example.test']);

    $this->get(route('sso.callback', ['code' => 'c', 'state' => $again['state']]))->assertRedirect(route('settings.sso'))->assertSessionHas('sso_test_failed');

    expect(app(SsoTestSignIn::class)->passedRecentlyBy($this->owner))->toBeFalse();
});

it('keeps the test sign-in for admins with the sso permission and a complete configuration', function () {
    $this->actingAs(userWithPermissions([Ability::ProjectsView]));
    (new FakeOidcProvider)->install();

    $this->get(route('sso.test'))->assertForbidden();

    auth()->logout();
    $this->get(route('sso.test'))->assertRedirect(route('login'));
});

it('does not log anyone in or change anything when a normal callback arrives while logged in', function () {
    (new FakeOidcProvider)->install();

    $this->get(route('sso.callback', ['code' => 'x', 'state' => 'y']))->assertRedirect(route('dashboard'));
});

it('asks for the typed word before lowering the audit log retention below the oldest entry', function () {
    auditDays(4);
    $this->travel(200)->days();

    expect(fn () => ($this->settings)()->update(['retention.audit_log_days' => 90], $this->owner))->toThrow(LockoutGuardException::class, 'Type DELETE');
    expect(fn () => ($this->settings)()->update(['retention.audit_log_days' => 90], $this->owner, ['retention.audit_log_days' => 'delete']))->toThrow(LockoutGuardException::class);

    ($this->settings)()->update(['retention.audit_log_days' => 90], $this->owner, ['retention.audit_log_days' => 'DELETE']);

    expect(($this->settings)()->auditLogRetentionDays())->toBe(90);
});

it('does not ask when lowering the retention deletes nothing or raising it', function () {
    ($this->settings)()->update(['retention.audit_log_days' => 100], $this->owner);
    ($this->settings)()->update(['retention.audit_log_days' => 500], $this->owner);

    expect(($this->settings)()->auditLogRetentionDays())->toBe(500);
});

it('lists every setting with its source and never a secret', function () {
    ($this->settings)()->update(['api.rate_limit_per_minute' => 77], $this->owner);
    ($this->settings)()->setSecret('sso.client_secret', 'listed-secret-value', $this->owner);

    $this->artisan('vault:settings', ['action' => 'list'])->expectsOutputToContain('api.rate_limit_per_minute')->assertSuccessful();

    Artisan::call('vault:settings', ['action' => 'list']);
    $output = Artisan::output();

    expect($output)->toContain('77')->toContain('dashboard')->toContain('sso.client_secret')->not->toContain('listed-secret-value');
});

it('gets and sets a setting from the console without a user and audits it as the console', function () {
    auth()->logout();

    $this->artisan('vault:settings', ['action' => 'set', 'key' => 'api.rate_limit_per_minute', 'value' => '120'])->assertSuccessful();
    $this->artisan('vault:settings', ['action' => 'get', 'key' => 'api.rate_limit_per_minute'])->expectsOutput('120')->assertSuccessful();

    $entry = AuditEntry::query()->where('event', 'settings_changed')->latest('sequence')->first();

    expect(($this->settings)()->apiRateLimitPerMinute())->toBe(120)
        ->and($entry->causer_id)->toBeNull()
        ->and($entry->properties['source'])->toBe('console');
});

it('refuses a bad value, an unknown key and an environment-locked key from the console', function () {
    $this->artisan('vault:settings', ['action' => 'set', 'key' => 'api.rate_limit_per_minute', 'value' => '0'])->assertFailed();
    $this->artisan('vault:settings', ['action' => 'set', 'key' => 'nope.key', 'value' => '1'])->assertFailed();

    config(['vault.api.rate_limit_per_minute' => 40]);
    $this->artisan('vault:settings', ['action' => 'set', 'key' => 'api.rate_limit_per_minute', 'value' => '50'])->expectsOutputToContain('VAULT_API_RATE_LIMIT')->assertFailed();
});

it('cannot set a secret from an argument or without interaction', function () {
    $this->artisan('vault:settings', ['action' => 'set', 'key' => 'sso.client_secret', 'value' => 'from-argument', '--no-interaction' => true])->expectsOutputToContain('secret cannot be set without interaction')->assertFailed();

    expect(($this->settings)()->hasSecret('sso.client_secret'))->toBeFalse()
        ->and(json_encode(DB::table('vault_settings')->get()))->not->toContain('from-argument');
});

it('resets one setting and the lockout settings in one step', function () {
    $this->actingAs($this->owner);
    ($this->settings)()->update(['api.rate_limit_per_minute' => 99, 'admin.allowed_ips' => '10.0.0.0/8', 'sso.client_id' => 'abc'], $this->owner, ['admin.allowed_ips' => true]);
    DB::table('vault_settings')->insert(['key' => 'sso.enforce', 'value' => json_encode(true)]);
    app(SetLockdown::class)->handle(true, $this->owner);

    $this->artisan('vault:settings', ['action' => 'reset', 'key' => 'api.rate_limit_per_minute'])->assertSuccessful();
    expect(($this->settings)()->apiRateLimitPerMinute())->toBe(60);

    $this->artisan('vault:settings', ['action' => 'reset', '--lockout' => true])->assertSuccessful();

    expect(($this->settings)()->get('admin.allowed_ips'))->toBeNull()
        ->and(DB::table('vault_settings')->where('key', 'sso.enforce')->exists())->toBeFalse()
        ->and(SetLockdown::isOn())->toBeFalse()
        ->and(($this->settings)()->get('sso.client_id'))->toBe('abc')
        ->and(AuditEntry::query()->where('event', 'settings_changed')->latest('sequence')->first()->properties['source'])->toBe('console');
});

it('alerts the owners when a setting changes from the console', function () {
    Notification::fake();
    User::factory()->create(['email' => 'second-owner@example.test']);
    ($this->settings)()->update(['notifications.mail' => 'ops@example.test'], $this->owner);

    $this->artisan('vault:settings', ['action' => 'set', 'key' => 'api.rate_limit_per_minute', 'value' => '75'])->assertSuccessful();

    Notification::assertSentOnDemand(SecurityAlertNotification::class);
});

it('lets a break-glass owner through the ip allowlist when the bypass is on and keeps others out', function () {
    $glass = User::factory()->withTwoFactor()->create(['is_break_glass' => true]);
    $normal = User::factory()->withTwoFactor()->admin()->create();
    ($this->settings)()->update(['admin.allowed_ips' => '10.0.0.0/8', 'security.break_glass_bypasses_ip_allowlist' => true], $this->owner, ['admin.allowed_ips' => true]);
    auth()->logout();

    $this->get(route('login'))->assertOk();
    $this->get(route('projects.index'))->assertForbidden();
    $this->post(route('login.store'), ['email' => $normal->email, 'password' => 'password'])->assertSessionHasErrors('email');
    $this->post(route('login.store'), ['email' => $glass->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));

    $this->actingAs($glass)->get(route('projects.index'))->assertOk();
    $this->actingAs($normal)->get(route('projects.index'))->assertForbidden();
});

it('keeps everyone out of a restricted address when the bypass is off', function () {
    ($this->settings)()->update(['admin.allowed_ips' => '10.0.0.0/8'], $this->owner, ['admin.allowed_ips' => true]);
    auth()->logout();

    $this->get(route('login'))->assertForbidden();
});
