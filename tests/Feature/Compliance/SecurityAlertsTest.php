<?php

use App\Actions\Vault\ChangeAdminRole;
use App\Actions\Vault\DeactivateAdmin;
use App\Enums\AdminRole;
use App\Models\AccessToken;
use App\Models\Project;
use App\Models\User;
use App\Vault\Audit\AuditChainVerifier;
use App\Vault\Compliance\SecurityAlertNotification;
use App\Vault\Compliance\SecurityAlerts;
use App\Vault\Health\AuditChainCheck;
use App\Vault\Settings\VaultSettings;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
    Http::fake(['hooks.slack.test/*' => Http::response('ok')]);
    $this->setter = User::factory()->create();
    app(VaultSettings::class)->update(['notifications.mail' => 'alerts@example.test'], $this->setter);
});

/**
 * The titles of every alert mail that went out, with the address each went to.
 *
 * @return list<array{to: string, title: string, body: string}>
 */
function sentAlerts(): array
{
    $sent = [];

    foreach (Notification::sentNotifications() as $notifiableClass => $byId) {
        foreach ($byId as $id => $byNotification) {
            foreach ($byNotification[SecurityAlertNotification::class] ?? [] as $record) {
                $mail = $record['notification']->toMail(new stdClass);
                $sent[] = ['to' => (string) ($record['notifiable']->routes['mail'] ?? $id), 'title' => $mail->subject, 'body' => implode(' ', $mail->introLines)];
            }
        }
    }

    return $sent;
}

function failedApiAttempts(int $count, string $ip = '203.0.113.9'): void
{
    foreach (range(1, $count) as $ignored) {
        test()->withServerVariables(['REMOTE_ADDR' => $ip])->getJson('/api/v1/projects/unknown-project/composer-auth', ['Authorization' => 'Bearer cv_'.str_repeat('z', 40)]);
    }
}

it('sends no alert and counts nothing without a channel', function () {
    Cache::flush();
    app(VaultSettings::class)->update(['notifications.mail' => ''], $this->setter);

    failedApiAttempts(25);

    Notification::assertNothingSent();
    Http::assertNothingSent();
});

it('alerts once at 20 failed attempts from one address and then throttles', function () {
    failedApiAttempts(19);
    expect(sentAlerts())->toBe([]);

    failedApiAttempts(1);
    $alerts = sentAlerts();

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]['title'])->toBe('Composer Vault alert: Failed token burst')
        ->and($alerts[0]['body'])->toContain('203.0.113.9');

    failedApiAttempts(30);
    expect(sentAlerts())->toHaveCount(1);

    failedApiAttempts(20, '198.51.100.4');
    expect(sentAlerts())->toHaveCount(2);

    $this->travel(61)->minutes();
    failedApiAttempts(20);
    expect(sentAlerts())->toHaveCount(3);
});

it('alerts when a token without an allowlist is used from a new address, and not the first time', function () {
    $project = Project::factory()->create(['slug' => 'shop']);
    [$token, $plain] = tokenWithPlain(AccessToken::factory()->for($project));
    $call = fn (string $ip) => $this->withServerVariables(['REMOTE_ADDR' => $ip])->getJson('/api/v1/projects/shop/composer-auth', ['Authorization' => 'Bearer '.$plain]);

    $call('198.51.100.1')->assertOk();
    expect(sentAlerts())->toBe([]);

    $call('198.51.100.1')->assertOk();
    expect(sentAlerts())->toBe([]);

    $call('198.51.100.2')->assertOk();
    $alerts = sentAlerts();

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]['title'])->toBe('Composer Vault alert: Token used from a new address')
        ->and($alerts[0]['body'])->toContain('198.51.100.2')->toContain($token->token_prefix)
        ->and($token->fresh()->recent_ips)->toBe(['198.51.100.1', '198.51.100.2']);

    $call('198.51.100.2');
    expect(sentAlerts())->toHaveCount(1);
});

it('keeps the last 20 addresses of a token and ignores tokens with an allowlist', function () {
    $project = Project::factory()->create(['slug' => 'shop']);
    [$token, $plain] = tokenWithPlain(AccessToken::factory()->for($project));

    foreach (range(1, 25) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.{$i}"])->getJson('/api/v1/projects/shop/composer-auth', ['Authorization' => 'Bearer '.$plain])->assertOk();
    }

    expect($token->fresh()->recent_ips)->toHaveCount(20)->and($token->fresh()->recent_ips[0])->toBe('198.51.100.6');

    [$locked, $lockedPlain] = tokenWithPlain(AccessToken::factory()->for($project)->withAllowedIps(['198.51.100.50']));
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.50'])->getJson('/api/v1/projects/shop/composer-auth', ['Authorization' => 'Bearer '.$lockedPlain])->assertOk();

    expect($locked->fresh()->recent_ips)->toBeNull();
});

it('alerts for an owner change and tells the other owners, never the person themselves', function () {
    $actor = User::factory()->create(['email' => 'actor@example.test']);
    $otherOwner = User::factory()->create(['email' => 'other-owner@example.test']);
    $promoted = User::factory()->admin()->create(['email' => 'promoted@example.test']);

    app(ChangeAdminRole::class)->handle($promoted, AdminRole::Owner, $actor);

    $to = collect(sentAlerts())->pluck('to')->all();

    expect($to)->toContain('alerts@example.test')->toContain('other-owner@example.test')->not->toContain('promoted@example.test')->not->toContain('actor@example.test');
});

it('alerts when an owner is deactivated and not for a deactivated admin', function () {
    $actor = User::factory()->create();
    $owner = User::factory()->create();
    $admin = User::factory()->admin()->create();

    app(DeactivateAdmin::class)->handle($admin, $actor);
    expect(sentAlerts())->toBe([]);

    app(DeactivateAdmin::class)->handle($owner, $actor);

    expect(collect(sentAlerts())->pluck('title')->unique()->all())->toBe(['Composer Vault alert: Owner change']);
});

it('alerts on a break-glass login and tells the other owners', function () {
    $glass = User::factory()->create(['is_break_glass' => true, 'email' => 'glass@example.test']);
    User::factory()->create(['email' => 'second-owner@example.test']);

    event(new Login('web', $glass, false));

    $to = collect(sentAlerts())->pluck('to')->all();

    expect(collect(sentAlerts())->pluck('title')->unique()->all())->toBe(['Composer Vault alert: Break-glass login'])
        ->and($to)->toContain('second-owner@example.test')->not->toContain('glass@example.test');
});

it('counts single sign-on rejections and alerts at the threshold', function () {
    $alerts = app(SecurityAlerts::class);

    foreach (range(1, 9) as $ignored) {
        $alerts->ssoRejection();
    }
    expect(sentAlerts())->toBe([]);

    $alerts->ssoRejection();
    $alerts->ssoRejection();

    expect(collect(sentAlerts())->pluck('title')->all())->toBe(['Composer Vault alert: Single sign-on rejection burst']);
});

it('alerts when the key is rotated', function () {
    $this->artisan('vault:rotate-key', ['--key' => vaultTestKey(2)])->assertSuccessful();

    expect(collect(sentAlerts())->pluck('title')->all())->toBe(['Composer Vault alert: Key rotation']);
});

it('alerts when the audit chain check finds a break', function () {
    DB::table('activity_log')->where('sequence', 1)->update(['event' => 'tampered']);
    app(AuditChainVerifier::class);
    Cache::forget(AuditChainCheck::CACHE_KEY);

    expect(app(AuditChainCheck::class)->run()->status->value)->toBe('failed')
        ->and(collect(sentAlerts())->pluck('title')->all())->toBe(['Composer Vault alert: Audit chain break']);
});

it('alerts when retention or a policy changes and not for other settings', function () {
    app(VaultSettings::class)->update(['organisation.name' => 'Acme'], $this->setter);
    expect(sentAlerts())->toBe([]);

    app(VaultSettings::class)->update(['retention.audit_log_days' => 400], $this->setter);

    $alerts = sentAlerts();

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]['title'])->toBe('Composer Vault alert: Retention or policy change')
        ->and($alerts[0]['body'])->toContain('retention.audit_log_days');
});

it('posts to the slack webhook without names or emails', function () {
    app(VaultSettings::class)->setSecret('notifications.slack_webhook', 'https://hooks.slack.test/T000/B000/xxxx', $this->setter);
    app(VaultSettings::class)->update(['notifications.mail' => ''], $this->setter);
    $actor = User::factory()->create(['name' => 'Alice Actor', 'email' => 'alice@example.test']);
    $promoted = User::factory()->admin()->create(['name' => 'Bob Promoted', 'email' => 'bob@example.test']);

    app(ChangeAdminRole::class)->handle($promoted, AdminRole::Owner, $actor);

    Http::assertSent(function ($request) {
        $text = $request['text'];

        return str_starts_with($request->url(), 'https://hooks.slack.test/') && str_contains($text, 'Owner change')
            && ! str_contains($text, 'Alice') && ! str_contains($text, 'Bob') && ! str_contains($text, '@example.test');
    });
});

it('does not fail the action when delivery fails', function () {
    Http::fake(['hooks.slack.test/*' => Http::response('nope', 500)]);
    app(VaultSettings::class)->setSecret('notifications.slack_webhook', 'https://hooks.slack.test/T000/B000/xxxx', $this->setter);
    $actor = User::factory()->create();
    $promoted = User::factory()->admin()->create();

    app(ChangeAdminRole::class)->handle($promoted, AdminRole::Owner, $actor);

    expect($promoted->fresh()->role)->toBe(AdminRole::Owner);
});

it('keeps every secret and address out of the alert text', function () {
    $actor = User::factory()->create();
    $promoted = User::factory()->admin()->create(['email' => 'private@example.test', 'name' => 'Private Person']);

    app(ChangeAdminRole::class)->handle($promoted, AdminRole::Owner, $actor);

    $text = json_encode(sentAlerts());

    expect($text)->not->toContain('Private Person')->not->toContain('private@example.test');
});

it('saves the notification settings, keeps the webhook write-only and lists the rules', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create());

    $component = Livewire::test('pages::settings.notifications')
        ->assertSee('Failed token burst')->assertSee('Each active rule fires')
        ->set('mail', 'ops@example.test')
        ->set('slackWebhook', 'https://hooks.slack.test/T/B/secretvalue')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('slackWebhook', '')
        ->assertSee('A webhook is stored');

    expect($component->html())->not->toContain('secretvalue')
        ->and(app(VaultSettings::class)->notificationMail())->toBe('ops@example.test')
        ->and(app(VaultSettings::class)->secret('notifications.slack_webhook'))->toBe('https://hooks.slack.test/T/B/secretvalue');
});

it('shows the rules off while no channel is set and refuses other roles', function () {
    app(VaultSettings::class)->update(['notifications.mail' => ''], $this->setter);
    $this->actingAs(User::factory()->withTwoFactor()->create());

    Livewire::test('pages::settings.notifications')->assertSee('No channel is set')->assertDontSee('Each active rule fires');

    $this->actingAs(User::factory()->withTwoFactor()->admin()->create());
    $this->get(route('settings.notifications'))->assertForbidden();
});

it('lets an environment address win and lock the field', function () {
    config(['vault.health.notify_mail' => 'env@example.test']);
    $this->actingAs(User::factory()->withTwoFactor()->create());

    Livewire::test('pages::settings.notifications')->assertSee('Set by the environment')->set('mail', 'tampered@example.test')->call('save');

    expect(app(VaultSettings::class)->notificationMail())->toBe('env@example.test');
});
