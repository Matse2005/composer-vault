<?php

use App\Enums\AlertRule;
use App\Models\AuditEntry;
use App\Models\User;
use App\Vault\Authorization\Ability;
use App\Vault\Compliance\SecurityAlertNotification;
use App\Vault\Compliance\SecurityAlerts;
use App\Vault\Settings\VaultSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->withTwoFactor()->create();
    $this->actingAs($this->owner);
});

it('saves the api limits and shows their source and default', function () {
    Livewire::test('pages::settings.api')
        ->assertSee('Source: default')->assertSee('Default: 60')
        ->set('rateLimit', '90')->set('failedAttempts', '8')
        ->call('save')->assertHasNoErrors()
        ->assertSee('Source: dashboard');

    expect(app(VaultSettings::class)->apiRateLimitPerMinute())->toBe(90)->and(app(VaultSettings::class)->apiFailedAttemptsPerMinute())->toBe(8);

    Livewire::test('pages::settings.api')->set('rateLimit', '0')->call('save')->assertHasErrors('rateLimit');
});

it('locks an api field that the environment sets and ignores a tampered value', function () {
    config(['vault.api.rate_limit_per_minute' => 33]);

    Livewire::test('pages::settings.api')->assertSee('Set by the environment')->assertSee('VAULT_API_RATE_LIMIT')->set('rateLimit', '99')->call('save');

    expect(app(VaultSettings::class)->apiRateLimitPerMinute())->toBe(33)
        ->and(DB::table('vault_settings')->where('key', 'api.rate_limit_per_minute')->exists())->toBeFalse();
});

it('saves the security settings, including the admin ip allowlist, and refuses a bad entry', function () {
    config(['vault.env.session_lifetime' => null]);

    Livewire::test('pages::settings.access')
        ->set('adminAllowedIps', '10.0.0.0/8, 192.0.2.5')->set('sessionIdleMinutes', '30')->set('invitationExpireHours', '24')
        ->call('save')->assertHasNoErrors();

    $settings = app(VaultSettings::class);

    expect($settings->get('admin.allowed_ips'))->toBe('10.0.0.0/8, 192.0.2.5')->and($settings->get('security.session_idle_minutes'))->toBe(30)->and($settings->invitationExpireHours())->toBe(24);

    Livewire::test('pages::settings.access')->set('adminAllowedIps', 'nope')->call('save')->assertHasErrors('adminAllowedIps');
});

it('saves the smtp settings and the password, and keeps the password out of the page', function () {
    config(['mail.default' => 'smtp', 'vault.env.mail_host' => null, 'vault.env.mail_port' => null, 'vault.env.mail_username' => null, 'vault.env.mail_password' => null, 'vault.env.mail_encryption' => null, 'vault.env.mail_from_address' => null, 'vault.env.mail_from_name' => null]);

    $component = Livewire::test('pages::settings.notifications')
        ->set('smtpHost', 'smtp.example.test')->set('smtpPort', '587')->set('smtpEncryption', 'tls')->set('smtpUsername', 'mailer')
        ->set('fromAddress', 'vault@example.test')->set('fromName', 'Vault')->set('smtpPassword', 'smtp-secret-value')
        ->call('save')->assertHasNoErrors()->assertSet('smtpPassword', '')->assertSee('A password is stored');

    $settings = app(VaultSettings::class);

    expect($settings->get('mail.host'))->toBe('smtp.example.test')->and($settings->get('mail.port'))->toBe(587)
        ->and($settings->secret('mail.password'))->toBe('smtp-secret-value')
        ->and($component->html())->not->toContain('smtp-secret-value')
        ->and(json_encode(AuditEntry::query()->get()->map->properties))->not->toContain('smtp-secret-value');
});

it('disables the smtp fields and ignores them when the mailer is not smtp', function () {
    config(['mail.default' => 'log']);

    Livewire::test('pages::settings.notifications')->assertSee('not SMTP')->set('smtpHost', 'tampered.example.test')->call('save');

    expect(DB::table('vault_settings')->where('key', 'mail.host')->exists())->toBeFalse();
});

it('turns an alert rule off and changes a threshold from the page', function () {
    Notification::fake();
    Http::fake();
    app(VaultSettings::class)->update(['notifications.mail' => 'alerts@example.test'], $this->owner);

    Livewire::test('pages::settings.notifications')
        ->set('rules.'.AlertRule::KeyRotation->value, false)->set('failedAttemptsThreshold', '5')
        ->call('save')->assertHasNoErrors();

    $alerts = app(SecurityAlerts::class);
    $alerts->send(AlertRule::KeyRotation, 'v2', 'The vault key was rotated.');
    Notification::assertNothingSent();

    foreach (range(1, 5) as $ignored) {
        $alerts->failedAttempt('203.0.113.50');
    }

    Notification::assertSentOnDemandTimes(SecurityAlertNotification::class, 1);
});

it('sends a test mail with the stored settings and reports a failure without the password', function () {
    app(VaultSettings::class)->update(['notifications.mail' => 'alerts@example.test'], $this->owner);
    Mail::fake();

    Livewire::test('pages::settings.notifications')->call('sendTestMail')->assertSee('Test mail sent');

    Mail::assertSentCount(0);
    expect(true)->toBeTrue();
});

it('says what failed in a test mail by type only', function () {
    app(VaultSettings::class)->update(['notifications.mail' => 'alerts@example.test'], $this->owner);
    app(VaultSettings::class)->setSecret('mail.password', 'never-show-this-password', $this->owner);
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);

    $component = Livewire::test('pages::settings.notifications')->call('sendTestMail');

    expect($component->get('testResult'))->toContain('The test mail failed')->not->toContain('never-show-this-password');
});

it('needs a mail address and a webhook for the test buttons', function () {
    Livewire::test('pages::settings.notifications')->call('sendTestMail')->assertSee('Set a mail address first')->call('sendTestSlack')->assertSee('Set a Slack webhook first');
});

it('posts the test message to the stored webhook and reports a failure by status only', function () {
    app(VaultSettings::class)->setSecret('notifications.slack_webhook', 'https://hooks.slack.test/T/B/webhook-secret', $this->owner);
    Http::fake(['hooks.slack.test/*' => Http::sequence()->push('ok', 200)->push('nope', 500)]);

    Livewire::test('pages::settings.notifications')->call('sendTestSlack')->assertSee('Test message sent');

    $failed = Livewire::test('pages::settings.notifications')->call('sendTestSlack');

    expect($failed->get('testResult'))->toContain('status 500')->not->toContain('webhook-secret');
});

it('lists the changes and restores an old value with the same permission', function () {
    $settings = app(VaultSettings::class);
    $settings->update(['api.rate_limit_per_minute' => 100], $this->owner);
    $settings->update(['api.rate_limit_per_minute' => 200], $this->owner);
    $sequence = AuditEntry::query()->where('event', 'settings_changed')->orderByDesc('sequence')->first()->sequence;

    Livewire::test('pages::settings.history')->assertSee('api.rate_limit_per_minute')->assertSee('Restore this value')
        ->call('restore', $sequence, 'api.rate_limit_per_minute')->assertHasNoErrors();

    expect(app(VaultSettings::class)->apiRateLimitPerMinute())->toBe(100)
        ->and(AuditEntry::query()->where('event', 'settings_changed')->count())->toBe(3);
});

it('does not offer to restore contact details, secrets, locked values or settings the admin may not change', function () {
    $settings = app(VaultSettings::class);
    $settings->update(['organisation.name' => 'Acme', 'api.rate_limit_per_minute' => 100], $this->owner);
    $settings->update(['organisation.name' => 'Acme 2', 'api.rate_limit_per_minute' => 150], $this->owner);
    $latest = AuditEntry::query()->where('event', 'settings_changed')->orderByDesc('sequence')->first()->sequence;

    $contact = Livewire::test('pages::settings.history')->call('restore', $latest, 'organisation.name');
    $contact->assertHasErrors('restore');

    config(['vault.api.rate_limit_per_minute' => 5]);
    Livewire::test('pages::settings.history')->call('restore', $latest, 'api.rate_limit_per_minute')->assertHasErrors('restore');
    config(['vault.api.rate_limit_per_minute' => null]);

    $logReader = userWithPermissions([Ability::LogsView, Ability::ProjectsView]);
    $this->actingAs($logReader);
    $component = Livewire::test('pages::settings.history')->assertDontSee('Restore this value');
    $component->call('restore', $latest, 'api.rate_limit_per_minute')->assertHasErrors('restore');

    expect(app(VaultSettings::class)->apiRateLimitPerMinute())->toBe(150);
});

it('shows the history to anyone with a settings permission or the logs, and hides it from others', function () {
    $this->actingAs(userWithPermissions([Ability::SettingsApi]));
    $this->get(route('settings.history'))->assertOk();

    $this->actingAs(userWithPermissions([Ability::LogsView]));
    $this->get(route('settings.history'))->assertOk();

    $this->actingAs(userWithPermissions([Ability::ProjectsView]));
    $this->get(route('settings.history'))->assertForbidden();
});

it('shows the settings links in the sidebar only for the permissions the admin holds', function () {
    $this->actingAs(userWithPermissions([Ability::SettingsApi, Ability::ProjectsView]));

    $this->get(route('projects.index'))->assertOk()->assertSee(route('settings.api'))->assertDontSee(route('settings.sso'))->assertDontSee(route('settings.retention'))->assertDontSee(route('settings.notifications'));
});

it('refuses the api page to a role without the permission, also through a tampered livewire call', function () {
    $this->actingAs(userWithPermissions([Ability::SettingsRetention]));

    $this->get(route('settings.api'))->assertForbidden();
    expect(fn () => app(VaultSettings::class)->update(['api.rate_limit_per_minute' => 1000], auth()->user()))->toThrow(AuthorizationException::class);
});
