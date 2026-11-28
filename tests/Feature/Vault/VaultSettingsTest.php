<?php

use App\Models\AuditEntry;
use App\Models\User;
use App\Vault\Settings\VaultSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->settings = fn (): VaultSettings => app(VaultSettings::class);
});

it('returns the defaults when nothing is stored', function () {
    config(['vault.access_log_retention_days' => 90]);

    $settings = ($this->settings)();

    expect($settings->accessLogRetentionDays())->toBe(90)
        ->and($settings->auditLogRetentionDays())->toBe(365)
        ->and($settings->invitationRetentionDays())->toBe(30)
        ->and($settings->tokenMaxLifetimeDays())->toBeNull()
        ->and($settings->credentialRotationDays())->toBeNull()
        ->and($settings->accessReviewIntervalDays())->toBe(90)
        ->and($settings->organisationName())->toBeNull();
});

it('falls back to the environment value for the access log retention', function () {
    config(['vault.access_log_retention_days' => 45]);

    expect(($this->settings)()->accessLogRetentionDays())->toBe(45);
});

it('reads a changed value in the same request', function () {
    $settings = ($this->settings)();

    expect($settings->auditLogRetentionDays())->toBe(365);

    $settings->update(['retention.audit_log_days' => 200], $this->actor);

    expect($settings->auditLogRetentionDays())->toBe(200)
        ->and(app(VaultSettings::class)->auditLogRetentionDays())->toBe(200);
});

it('stores a value and removes it again when it is cleared', function () {
    ($this->settings)()->update(['policy.token_max_lifetime_days' => 30], $this->actor);

    expect(DB::table('vault_settings')->where('key', 'policy.token_max_lifetime_days')->exists())->toBeTrue();

    ($this->settings)()->update(['policy.token_max_lifetime_days' => null], $this->actor);

    expect(DB::table('vault_settings')->count())->toBe(0)
        ->and(($this->settings)()->tokenMaxLifetimeDays())->toBeNull();
});

it('refuses a value below its minimum', function (string $key, int $below) {
    expect(fn () => ($this->settings)()->update([$key => $below], $this->actor))->toThrow(ValidationException::class);

    expect(DB::table('vault_settings')->count())->toBe(0)
        ->and(AuditEntry::query()->count())->toBe(0);
})->with([
    'access log' => ['retention.access_log_days', 29],
    'audit log' => ['retention.audit_log_days', 89],
    'invitations' => ['retention.invitation_days', 0],
    'token lifetime' => ['policy.token_max_lifetime_days', 0],
    'credential rotation' => ['policy.credential_rotation_days', 29],
    'access review interval' => ['policy.access_review_interval_days', 29],
]);

it('accepts the minimum itself', function () {
    ($this->settings)()->update(['retention.audit_log_days' => 90, 'retention.access_log_days' => 30], $this->actor);

    expect(($this->settings)()->auditLogRetentionDays())->toBe(90)
        ->and(($this->settings)()->accessLogRetentionDays())->toBe(30);
});

it('refuses a setting that is not defined', function () {
    expect(fn () => ($this->settings)()->update(['unknown.key' => 1], $this->actor))->toThrow(ValidationException::class);
});

it('writes one audit entry per change with the old and new values', function () {
    ($this->settings)()->update(['retention.audit_log_days' => 200, 'policy.credential_rotation_days' => 60], $this->actor);
    ($this->settings)()->update(['retention.audit_log_days' => 300], $this->actor);

    $entries = AuditEntry::query()->where('event', 'settings_changed')->orderBy('sequence')->get();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->properties->toArray())->toMatchArray([
            'changes' => [
                'retention.audit_log_days' => ['old' => 365, 'new' => 200],
                'policy.credential_rotation_days' => ['old' => null, 'new' => 60],
            ],
        ])
        ->and($entries[1]->properties->toArray())->toMatchArray([
            'changes' => ['retention.audit_log_days' => ['old' => 200, 'new' => 300]],
        ])
        ->and($entries[0]->causer_id)->toBe($this->actor->id);
});

it('writes no audit entry when nothing changed', function () {
    ($this->settings)()->update(['retention.audit_log_days' => 365], $this->actor);

    expect(AuditEntry::query()->count())->toBe(0);
});

it('keeps contact details out of the audit entry', function () {
    ($this->settings)()->update([
        'organisation.name' => 'Acme Corp',
        'organisation.security_contact' => 'security@acme.test',
    ], $this->actor);

    $entry = AuditEntry::query()->where('event', 'settings_changed')->sole();

    expect(json_encode($entry->properties))->not->toContain('security@acme.test')->not->toContain('Acme Corp')
        ->and($entry->properties->toArray()['changes'])->toBe([
            'organisation.name' => ['old' => 'changed', 'new' => 'changed'],
            'organisation.security_contact' => ['old' => 'changed', 'new' => 'changed'],
        ])
        ->and(($this->settings)()->organisationName())->toBe('Acme Corp')
        ->and(($this->settings)()->securityContact())->toBe('security@acme.test');
});

it('validates the contact addresses', function () {
    expect(fn () => ($this->settings)()->update(['organisation.privacy_contact' => 'not an address'], $this->actor))
        ->toThrow(ValidationException::class);
});
