<?php

use App\Enums\AdminRole;
use App\Enums\AuditAction;
use App\Enums\ControlStatus;
use App\Models\AccessReview;
use App\Models\Credential;
use App\Models\Incident;
use App\Models\Project;
use App\Models\User;
use App\Vault\Audit\AuditLogger;
use App\Vault\Compliance\ComplianceReport;
use App\Vault\Health\HealthRunner;
use App\Vault\Settings\VaultSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * The status of one control by name.
 */
function controlStatus(string $name): ControlStatus
{
    return collect(app(ComplianceReport::class)->controls())->firstWhere('control', $name)['status'];
}

it('reports every control with a status, evidence and a topic', function () {
    $controls = app(ComplianceReport::class)->controls();

    expect(collect($controls)->pluck('control')->all())->toBe([
        'Multi-factor authentication',
        'Single sign-on and central deprovisioning',
        'Access control and least privilege',
        'Encryption of secrets',
        'Logging and integrity',
        'Incident handling',
        'Retention',
        'Health',
    ]);

    foreach ($controls as $row) {
        expect($row['status'])->toBeInstanceOf(ControlStatus::class)->and($row['evidence'])->not->toBe('')->and($row['supports'])->not->toBe('');
    }
});

it('rates multi-factor authentication by the share of admins', function () {
    expect(controlStatus('Multi-factor authentication'))->toBe(ControlStatus::NotInPlace);

    User::factory()->withTwoFactor()->create();
    expect(controlStatus('Multi-factor authentication'))->toBe(ControlStatus::InPlace);

    User::factory()->create();
    expect(controlStatus('Multi-factor authentication'))->toBe(ControlStatus::Partly);

    User::query()->whereNull('two_factor_confirmed_at')->delete();
    User::factory()->create();
    User::query()->update(['two_factor_confirmed_at' => null]);
    expect(controlStatus('Multi-factor authentication'))->toBe(ControlStatus::NotInPlace);

    config(['vault.sso.enabled' => true, 'vault.sso.enforce' => true, 'vault.sso.trust_idp_mfa' => true]);
    expect(controlStatus('Multi-factor authentication'))->toBe(ControlStatus::InPlace);
});

it('rates single sign-on by enabled, enforced and break-glass', function () {
    expect(controlStatus('Single sign-on and central deprovisioning'))->toBe(ControlStatus::NotInPlace);

    config(['vault.sso.enabled' => true, 'vault.sso.issuer' => 'https://idp.example.test', 'vault.sso.client_id' => 'x', 'vault.sso.client_secret' => 'y']);
    expect(controlStatus('Single sign-on and central deprovisioning'))->toBe(ControlStatus::Partly);

    config(['vault.sso.enforce' => true]);
    expect(controlStatus('Single sign-on and central deprovisioning'))->toBe(ControlStatus::Partly);

    User::factory()->create(['is_break_glass' => true]);
    expect(controlStatus('Single sign-on and central deprovisioning'))->toBe(ControlStatus::InPlace);
});

it('rates access control by the last review', function () {
    User::factory()->create();
    expect(controlStatus('Access control and least privilege'))->toBe(ControlStatus::NotInPlace);

    User::factory()->admin()->create();
    expect(controlStatus('Access control and least privilege'))->toBe(ControlStatus::Partly);

    AccessReview::factory()->create(['completed_at' => now()->subDays(10)]);
    expect(controlStatus('Access control and least privilege'))->toBe(ControlStatus::InPlace);

    app(VaultSettings::class)->update(['policy.access_review_interval_days' => 30], User::factory()->create());
    $this->travel(40)->days();
    expect(controlStatus('Access control and least privilege'))->toBe(ControlStatus::Partly);
});

it('rates encryption by the key version of the credentials', function () {
    expect(controlStatus('Encryption of secrets'))->toBe(ControlStatus::InPlace);

    Credential::factory()->create();
    Credential::factory()->create();
    expect(controlStatus('Encryption of secrets'))->toBe(ControlStatus::InPlace);

    Credential::query()->limit(1)->update(['key_version' => 0]);
    expect(controlStatus('Encryption of secrets'))->toBe(ControlStatus::Partly);

    config(['vault.key' => null]);
    expect(controlStatus('Encryption of secrets'))->toBe(ControlStatus::NotInPlace);
});

it('rates logging by the chain and the event stream', function () {
    expect(controlStatus('Logging and integrity'))->toBe(ControlStatus::Partly);

    config(['vault.security_log_channel' => 'stack']);
    expect(controlStatus('Logging and integrity'))->toBe(ControlStatus::InPlace);

    app(AuditLogger::class)->record(AuditAction::ProjectCreated, Project::factory()->create());
    DB::table('activity_log')->update(['event' => 'tampered']);
    expect(controlStatus('Logging and integrity'))->toBe(ControlStatus::NotInPlace);
});

it('rates incident handling by the alert channel and counts open incidents', function () {
    Incident::factory()->create();
    Incident::factory()->closed()->create();

    expect(controlStatus('Incident handling'))->toBe(ControlStatus::NotInPlace);

    config(['vault.health.notify_mail' => 'ops@example.test']);
    $row = collect(app(ComplianceReport::class)->controls())->firstWhere('control', 'Incident handling');

    expect($row['status'])->toBe(ControlStatus::InPlace)->and($row['evidence'])->toContain('Open incidents: 1');
});

it('lists the retention values and rates health by the latest run', function () {
    $retention = collect(app(ComplianceReport::class)->controls())->firstWhere('control', 'Retention');

    expect($retention['evidence'])->toContain('Access log 90 days')->toContain('audit log 365 days')->toContain('invitations 30 days');

    expect(controlStatus('Health'))->toBe(ControlStatus::NotInPlace);

    app(HealthRunner::class)->runFresh();
    expect(controlStatus('Health'))->toBeIn([ControlStatus::NotInPlace, ControlStatus::Partly, ControlStatus::InPlace]);
});

it('prints the same controls on the command, as json and on the page', function () {
    User::factory()->withTwoFactor()->create();
    $names = collect(app(ComplianceReport::class)->controls())->pluck('control')->all();

    Artisan::call('vault:compliance-report', ['--json' => true]);
    $json = json_decode(Artisan::output(), true);

    expect(array_keys($json))->toBe(['disclaimer', 'version', 'controls', 'settings'])
        ->and($json['disclaimer'])->toBe(ComplianceReport::DISCLAIMER)
        ->and(array_keys($json['controls'][0]))->toBe(['control', 'status', 'evidence', 'supports'])
        ->and(collect($json['controls'])->pluck('control')->all())->toBe($names)
        ->and(collect($json['controls'])->pluck('status')->every(fn ($status) => in_array($status, ['in_place', 'partly', 'not_in_place'], true)))->toBeTrue();

    Artisan::call('vault:compliance-report');
    $table = Artisan::output();

    foreach ($names as $name) {
        expect($table)->toContain($name);
    }

    expect($table)->toStartWith(ComplianceReport::DISCLAIMER);

    $this->actingAs(User::factory()->withTwoFactor()->create());
    $component = Livewire::test('pages::compliance.report')->assertSee(ComplianceReport::DISCLAIMER);

    foreach ($names as $name) {
        $component->assertSee($name);
    }
});

it('shows the page to every role', function (string $role) {
    $this->actingAs(User::factory()->withTwoFactor()->create(['role' => AdminRole::from($role)]));

    $this->get(route('compliance.report'))->assertOk()->assertSee('Compliance report')->assertSee('not a certification');
})->with(['owner', 'admin', 'auditor']);

it('holds no personal data and no secret', function () {
    $user = User::factory()->withTwoFactor()->create(['name' => 'Report Person', 'email' => 'report.person@example.test']);
    app(VaultSettings::class)->update(['organisation.name' => 'Acme Org', 'organisation.security_contact' => 'sec@acme.test', 'notifications.mail' => 'alerts@acme.test'], $user);
    app(VaultSettings::class)->setSecret('sso.client_secret', 'report-secret-value', $user);
    config(['vault.health.notify_mail' => 'env-alerts@acme.test']);

    $all = json_encode(app(ComplianceReport::class)->toArray()).Artisan::output();

    foreach (['Report Person', 'report.person@example.test', 'Acme Org', 'sec@acme.test', 'alerts@acme.test', 'env-alerts@acme.test', 'report-secret-value'] as $value) {
        expect($all)->not->toContain($value);
    }
});

it('never calls the vault compliant', function () {
    $text = json_encode(app(ComplianceReport::class)->toArray());

    expect(strtolower($text))->not->toContain('gdpr compliant')->not->toContain('nis2 compliant')->not->toContain('is compliant');
});
