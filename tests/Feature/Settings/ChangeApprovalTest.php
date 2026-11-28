<?php

use App\Actions\Vault\ChangeAdminRole;
use App\Actions\Vault\DecidePendingChange;
use App\Actions\Vault\SaveRole;
use App\Enums\AdminRole;
use App\Exceptions\Vault\ApprovalRefusedException;
use App\Exceptions\Vault\ChangeProposedException;
use App\Models\AuditEntry;
use App\Models\PendingChange;
use App\Models\Role;
use App\Models\User;
use App\Vault\Authorization\Ability;
use App\Vault\Settings\VaultSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->first = User::factory()->withTwoFactor()->create(['name' => 'First Owner', 'email' => 'first@example.test']);
    $this->second = User::factory()->withTwoFactor()->create(['name' => 'Second Owner', 'email' => 'second@example.test']);
    app(VaultSettings::class)->update(['approval.enabled' => true], $this->first);
    $this->settings = fn (): VaultSettings => app(VaultSettings::class);
    $this->decide = fn (): DecidePendingChange => app(DecidePendingChange::class);
    $this->actingAs($this->first);
});

it('applies a critical change at once while approval is off', function () {
    DB::table('vault_settings')->where('key', 'approval.enabled')->delete();
    app()->forgetInstance(VaultSettings::class);

    $pending = ($this->settings)()->update(['retention.invitation_days' => 20], $this->first);

    expect($pending)->toBe([])->and(($this->settings)()->invitationRetentionDays())->toBe(20)->and(PendingChange::query()->count())->toBe(0);
});

it('stores a critical setting as a pending change and applies the others', function () {
    $pending = ($this->settings)()->update(['retention.invitation_days' => 20, 'api.rate_limit_per_minute' => 80], $this->first);

    $change = PendingChange::query()->sole();

    expect($pending)->toBe(['retention.invitation_days'])
        ->and(($this->settings)()->invitationRetentionDays())->toBe(30)
        ->and(($this->settings)()->apiRateLimitPerMinute())->toBe(80)
        ->and($change->kind)->toBe('setting')->and($change->payload['value'])->toBe(20)->and($change->proposed_by)->toBe($this->first->id)
        ->and($change->expires_at->between(now()->addHours(23), now()->addHours(25)))->toBeTrue()
        ->and(AuditEntry::query()->where('event', 'change_proposed')->count())->toBe(1);
});

it('does not propose a change that changes nothing', function () {
    expect(($this->settings)()->update(['retention.invitation_days' => 30], $this->first))->toBe([])
        ->and(PendingChange::query()->count())->toBe(0);
});

it('lets another owner approve and applies the change with both ids in the audit entry', function () {
    ($this->settings)()->update(['retention.invitation_days' => 20], $this->first);
    $change = PendingChange::query()->sole();

    ($this->decide)()->approve($change, $this->second);

    $entry = AuditEntry::query()->where('event', 'settings_changed')->latest('sequence')->first();

    expect(($this->settings)()->invitationRetentionDays())->toBe(20)
        ->and($change->fresh()->status)->toBe('approved')->and($change->fresh()->decided_by)->toBe($this->second->id)
        ->and($entry->properties->toArray())->toMatchArray(['proposed_by' => $this->first->id, 'approved_by' => $this->second->id])
        ->and($entry->causer_id)->toBe($this->second->id)
        ->and(AuditEntry::query()->where('event', 'change_approved')->count())->toBe(1);
});

it('refuses self-approval, a second decision and an expired change', function () {
    ($this->settings)()->update(['retention.invitation_days' => 20], $this->first);
    $change = PendingChange::query()->sole();

    expect(fn () => ($this->decide)()->approve($change, $this->first))->toThrow(ApprovalRefusedException::class, 'own change');

    ($this->decide)()->approve($change, $this->second);

    expect(fn () => ($this->decide)()->approve($change, $this->second))->toThrow(ApprovalRefusedException::class, 'decided already');

    ($this->settings)()->update(['retention.invitation_days' => 25], $this->first);
    $old = PendingChange::query()->where('status', 'pending')->sole();
    $this->travel(25)->hours();

    expect(fn () => ($this->decide)()->approve($old, $this->second))->toThrow(ApprovalRefusedException::class, 'expired')
        ->and(($this->settings)()->invitationRetentionDays())->toBe(20)
        ->and($old->fresh()->isOpen())->toBeFalse();
});

it('lets the other owner reject, and the proposer withdraw', function () {
    ($this->settings)()->update(['retention.invitation_days' => 20], $this->first);
    $change = PendingChange::query()->sole();

    ($this->decide)()->reject($change, $this->first);

    expect($change->fresh()->status)->toBe('rejected')->and(($this->settings)()->invitationRetentionDays())->toBe(30);

    ($this->settings)()->update(['retention.invitation_days' => 21], $this->first);
    ($this->decide)()->reject(PendingChange::query()->where('status', 'pending')->sole(), $this->second);

    expect(PendingChange::query()->where('status', 'rejected')->count())->toBe(2);
});

it('applies changes directly with one active owner and says why', function () {
    $this->second->forceFill(['deactivated_at' => now()])->save();

    expect(($this->settings)()->update(['retention.invitation_days' => 20], $this->first))->toBe([])
        ->and(($this->settings)()->invitationRetentionDays())->toBe(20);

    Livewire::test('pages::settings.approvals')->assertSee('only one Owner is active');
});

it('stores a critical secret encrypted as a pending change and applies it on approval', function () {
    $pending = ($this->settings)()->setSecret('sso.client_secret', 'pending-secret-value', $this->first);
    $change = PendingChange::query()->sole();

    expect($pending)->toBeTrue()->and(($this->settings)()->hasSecret('sso.client_secret'))->toBeFalse()
        ->and($change->kind)->toBe('secret')
        ->and(json_encode(DB::table('pending_setting_changes')->get()))->not->toContain('pending-secret-value');

    ($this->decide)()->approve($change, $this->second);

    expect(($this->settings)()->secret('sso.client_secret'))->toBe('pending-secret-value')
        ->and(json_encode(DB::table('activity_log')->get()))->not->toContain('pending-secret-value');
});

it('does not hold a secret that is not critical', function () {
    expect(($this->settings)()->setSecret('notifications.slack_webhook', 'https://hooks.example.test/x', $this->first))->toBeFalse()
        ->and(($this->settings)()->hasSecret('notifications.slack_webhook'))->toBeTrue();
});

it('holds the permissions of a role for approval and applies them when approved', function () {
    $role = Role::factory()->withPermissions([Ability::ProjectsView])->create();

    expect(fn () => app(SaveRole::class)->handle($role, $role->name, [Ability::ProjectsView, Ability::LogsView], [], $this->first))->toThrow(ChangeProposedException::class);

    expect($role->fresh()->permissions)->toBe([Ability::ProjectsView])->and(PendingChange::query()->sole()->kind)->toBe('role');

    ($this->decide)()->approve(PendingChange::query()->sole(), $this->second);

    expect($role->fresh()->permissions)->toBe([Ability::ProjectsView, Ability::LogsView])
        ->and(AuditEntry::query()->where('event', 'role_changed')->latest('sequence')->first()->properties['approved_by'])->toBe($this->second->id);
});

it('does not hold a new role or a change of the groups only', function () {
    $role = app(SaveRole::class)->handle(null, 'Fresh', [Ability::ProjectsView], [], $this->first);
    app(SaveRole::class)->handle($role, 'Fresh', [Ability::ProjectsView], ['group-a'], $this->first);

    expect(PendingChange::query()->count())->toBe(0)->and($role->fresh()->sso_groups)->toBe(['group-a']);
});

it('holds the giving of the owner role and applies it when approved', function () {
    $admin = User::factory()->admin()->create();

    expect(fn () => app(ChangeAdminRole::class)->handle($admin, AdminRole::Owner, $this->first))->toThrow(ChangeProposedException::class);

    expect($admin->fresh()->role)->toBe(AdminRole::Admin)->and(PendingChange::query()->sole()->kind)->toBe('owner');

    ($this->decide)()->approve(PendingChange::query()->sole(), $this->second);

    expect($admin->fresh()->role)->toBe(AdminRole::Owner);

    app(ChangeAdminRole::class)->handle(User::factory()->admin()->create(), AdminRole::Auditor, $this->first);
    expect(PendingChange::query()->count())->toBe(1);
});

it('lets only an owner use the approvals page and the setting', function () {
    $other = userWithPermissions(Ability::all());
    $this->actingAs($other);

    expect(fn () => app(DecidePendingChange::class)->reject(new PendingChange, $other))->toThrow(AuthorizationException::class);
    $this->get(route('settings.approvals'))->assertForbidden();

    $this->actingAs($this->first)->get(route('settings.approvals'))->assertOk();
});

it('shows the waiting changes and lets the second owner approve from the page, not the proposer', function () {
    ($this->settings)()->update(['retention.invitation_days' => 20], $this->first);
    $id = PendingChange::query()->sole()->id;

    Livewire::test('pages::settings.approvals')->assertSee('retention.invitation_days')->assertSee('Withdraw')->assertDontSee('Approve')->call('approve', $id)->assertHasErrors('decision');

    $this->actingAs($this->second);

    Livewire::test('pages::settings.approvals')->assertSee('First Owner')->assertSee('Approve')->call('approve', $id)->assertHasNoErrors();

    expect(($this->settings)()->invitationRetentionDays())->toBe(20);
});

it('tells the other owners and the alert channel about a proposal, without names', function () {
    Notification::fake();
    ($this->settings)()->update(['notifications.mail' => 'alerts@example.test'], $this->first);

    ($this->settings)()->update(['retention.invitation_days' => 20], $this->first);

    $to = collect(Notification::sentNotifications())->flatMap(fn ($byId) => collect($byId)->flatMap(fn ($byClass) => collect($byClass)->flatten(1)))->map(fn ($sent) => (string) ($sent['notifiable']->routes['mail'] ?? ''))->all();

    expect($to)->toContain('second@example.test')->not->toContain('first@example.test');
});

it('is bypassed by the console, which alerts the owners instead', function () {
    $this->artisan('vault:settings', ['action' => 'set', 'key' => 'retention.invitation_days', 'value' => '15'])->assertSuccessful();

    expect(($this->settings)()->invitationRetentionDays())->toBe(15)->and(PendingChange::query()->count())->toBe(0);
});

it('turns approval off only through approval too', function () {
    $pending = ($this->settings)()->update(['approval.enabled' => false], $this->first);

    expect($pending)->toBe(['approval.enabled'])->and((bool) ($this->settings)()->get('approval.enabled'))->toBeTrue();

    ($this->decide)()->approve(PendingChange::query()->sole(), $this->second);

    expect((bool) ($this->settings)()->get('approval.enabled'))->toBeFalse();
});

it('shows the setting to owners on the security page and ignores a tampered value from others', function () {
    DB::table('vault_settings')->where('key', 'approval.enabled')->delete();
    app()->forgetInstance(VaultSettings::class);

    Livewire::test('pages::settings.access')->assertSee('Require a second Owner to approve critical changes')->set('approvalEnabled', true)->call('save')->assertHasNoErrors();

    expect((bool) ($this->settings)()->get('approval.enabled'))->toBeTrue();

    $manager = userWithPermissions([Ability::SettingsSecurity]);
    $this->actingAs($manager);

    Livewire::test('pages::settings.access')->assertDontSee('Require a second Owner')->set('approvalEnabled', false)->call('save');

    expect((bool) ($this->settings)()->get('approval.enabled'))->toBeTrue();
});
