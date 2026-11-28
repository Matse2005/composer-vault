<?php

use App\Actions\Vault\AcceptInvitation;
use App\Actions\Vault\ChangeAdminRole;
use App\Actions\Vault\DeleteRole;
use App\Actions\Vault\InviteAdmin;
use App\Actions\Vault\SaveRole;
use App\Enums\AdminRole;
use App\Exceptions\Vault\RoleEscalationException;
use App\Models\AuditEntry;
use App\Models\Role;
use App\Models\User;
use App\Vault\Authorization\Ability;
use App\Vault\Settings\VaultSettings;
use App\Vault\Sso\SsoConfig;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Tests\Support\FakeOidcProvider;

it('seeds the three system roles with the permissions of the phase 8 matrix', function () {
    $roles = Role::query()->where('is_system', true)->get()->keyBy('slug');

    expect($roles->keys()->sort()->values()->all())->toBe(['admin', 'auditor', 'owner'])
        ->and($roles['owner']->permissions)->toEqualCanonicalizing(Ability::all())
        ->and($roles['admin']->permissions)->toContain(Ability::ProjectsManage, Ability::CredentialsManage, Ability::TokensManage)->not->toContain(Ability::AdminsManage, Ability::SettingsSso)
        ->and($roles['auditor']->permissions)->toContain(Ability::LogsExport)->not->toContain(Ability::ProjectsManage);
});

it('lists every permission once with a description', function () {
    $all = Ability::all();

    expect($all)->toHaveCount(22)->and(array_unique($all))->toHaveCount(22)->and(array_merge(...array_values(Ability::groups())))->each->not->toBe('');
});

it('lets a permission through the gate only for a role that holds it, and denies a deactivated admin', function (string $permission) {
    $with = userWithPermissions([$permission]);
    $without = userWithPermissions(array_values(array_diff(Ability::all(), [$permission])));
    $deactivated = userWithPermissions([$permission]);
    $deactivated->forceFill(['deactivated_at' => now()])->save();

    expect($with->can($permission))->toBeTrue()
        ->and($without->can($permission))->toBeFalse()
        ->and($deactivated->can($permission))->toBeFalse()
        ->and(User::factory()->create()->can($permission))->toBeTrue();
})->with(Ability::all());

it('gives the owner every permission whatever the table says', function () {
    Role::query()->where('slug', 'owner')->update(['permissions' => json_encode([])]);

    expect(User::factory()->create()->can(Ability::SettingsSso))->toBeTrue();
});

it('sends a role without the permission to a 403 on each admin page', function (string $route, string $permission) {
    $this->actingAs(userWithPermissions(array_values(array_diff(Ability::all(), [$permission]))));
    $this->get(route($route))->assertForbidden();

    $this->actingAs(userWithPermissions([$permission]));
    $this->get(route($route))->assertOk();
})->with([
    'projects' => ['projects.index', Ability::ProjectsView],
    'credentials' => ['credentials.index', Ability::CredentialsView],
    'tokens' => ['tokens.index', Ability::TokensView],
    'access log' => ['logs.access', Ability::LogsView],
    'audit log' => ['logs.audit', Ability::LogsView],
    'security status' => ['security-status', Ability::LogsView],
    'admins' => ['admins.index', Ability::AdminsView],
    'roles' => ['roles.index', Ability::RolesManage],
    'organisation' => ['settings.organisation', Ability::SettingsOrganisation],
    'security settings' => ['settings.access', Ability::SettingsSecurity],
    'policies' => ['settings.policies', Ability::SettingsPolicies],
    'retention' => ['settings.retention', Ability::SettingsRetention],
    'sso' => ['settings.sso', Ability::SettingsSso],
    'notifications' => ['settings.notifications', Ability::SettingsNotifications],
    'compliance report' => ['compliance.report', Ability::ComplianceView],
    'incidents' => ['incidents', Ability::IncidentsManage],
]);

it('checks the permission of each setting when it is changed', function () {
    $settings = app(VaultSettings::class);
    $onlyApi = userWithPermissions([Ability::SettingsApi]);

    $settings->update(['api.rate_limit_per_minute' => 80], $onlyApi);

    expect($settings->apiRateLimitPerMinute())->toBe(80)
        ->and(fn () => $settings->update(['retention.audit_log_days' => 100], $onlyApi))->toThrow(AuthorizationException::class)
        ->and(fn () => $settings->update(['api.rate_limit_per_minute' => 90, 'retention.audit_log_days' => 100], $onlyApi))->toThrow(AuthorizationException::class)
        ->and(fn () => $settings->setSecret('sso.client_secret', 'x', $onlyApi))->toThrow(AuthorizationException::class);
});

it('creates a role, audits the permissions and lets an admin hold it', function () {
    $owner = User::factory()->create();

    $role = app(SaveRole::class)->handle(null, 'Deployer', [Ability::ProjectsView, Ability::TokensManage], ['deployers', 'ci-team'], $owner);

    expect($role->slug)->toBe('deployer')->and($role->is_system)->toBeFalse()->and($role->sso_groups)->toBe(['deployers', 'ci-team'])
        ->and(AuditEntry::query()->where('event', 'role_created')->sole()->properties->toArray())->toEqual(['name' => 'Deployer', 'permissions' => [Ability::ProjectsView, Ability::TokensManage]]);

    $admin = User::factory()->admin()->create();
    app(ChangeAdminRole::class)->handle($admin, $role, $owner);

    expect($admin->fresh()->role)->toBe(AdminRole::Custom)->and($admin->fresh()->can(Ability::TokensManage))->toBeTrue()->and($admin->fresh()->can(Ability::ProjectsManage))->toBeFalse();
});

it('audits the added and removed permissions when a role changes, and applies the change at once', function () {
    $owner = User::factory()->create();
    $role = Role::factory()->withPermissions([Ability::ProjectsView, Ability::LogsView])->create();
    $holder = User::factory()->create();
    $role->assignTo($holder);
    $holder->save();
    expect($holder->can(Ability::LogsView))->toBeTrue();

    app(SaveRole::class)->handle($role, $role->name, [Ability::ProjectsView, Ability::TokensView], [], $owner);

    $entry = AuditEntry::query()->where('event', 'role_changed')->sole();

    expect($entry->properties->toArray())->toEqual(['added' => [Ability::TokensView], 'removed' => [Ability::LogsView], 'groups_changed' => false])
        ->and($holder->fresh()->can(Ability::LogsView))->toBeFalse()
        ->and($holder->fresh()->can(Ability::TokensView))->toBeTrue();
});

it('lets the admin role be edited but never renamed, deleted or the owner role touched', function () {
    $owner = User::factory()->create();
    $admin = Role::forAdminRole(AdminRole::Admin);

    app(SaveRole::class)->handle($admin, 'Renamed', array_values(array_diff($admin->permissions, [Ability::HealthRun])), [], $owner);

    expect($admin->fresh()->name)->toBe('Admin')->and($admin->fresh()->permissions)->not->toContain(Ability::HealthRun)
        ->and(fn () => app(DeleteRole::class)->handle($admin, $owner))->toThrow(RoleEscalationException::class)
        ->and(fn () => app(SaveRole::class)->handle(Role::forAdminRole(AdminRole::Owner), 'Owner', [], [], $owner))->toThrow(RoleEscalationException::class);
});

it('does not grant a permission the actor does not hold', function () {
    $manager = userWithPermissions([Ability::RolesManage, Ability::ProjectsView]);

    expect(fn () => app(SaveRole::class)->handle(null, 'Sneaky', [Ability::ProjectsView, Ability::SettingsSso], [], $manager))->toThrow(RoleEscalationException::class, 'settings.sso');

    $role = app(SaveRole::class)->handle(null, 'Fine', [Ability::ProjectsView], [], $manager);

    expect($role->exists)->toBeTrue()
        ->and(fn () => app(SaveRole::class)->handle($role, 'Fine', [Ability::ProjectsView, Ability::AdminsManage], [], $manager))->toThrow(RoleEscalationException::class);
});

it('does not let anyone give a role with a permission they lack, to someone else or in an invitation', function () {
    $hr = userWithPermissions([Ability::AdminsManage, Ability::AdminsView]);
    $target = User::factory()->auditor()->create();
    $strong = Role::factory()->withPermissions([Ability::SettingsSso])->create();

    expect(fn () => app(ChangeAdminRole::class)->handle($target, $strong, $hr))->toThrow(RoleEscalationException::class)
        ->and(fn () => app(InviteAdmin::class)->handle('new@example.test', $hr, $strong))->toThrow(RoleEscalationException::class)
        ->and(fn () => app(InviteAdmin::class)->handle('new@example.test', $hr, AdminRole::Owner))->toThrow(RoleEscalationException::class);
});

it('lets only an owner assign or remove the owner role', function () {
    $everything = Role::factory()->withPermissions(Ability::all())->create();
    $almostOwner = User::factory()->withTwoFactor()->create();
    $everything->assignTo($almostOwner);
    $almostOwner->save();
    $other = User::factory()->admin()->create();
    $owner = User::factory()->create();

    expect(fn () => app(ChangeAdminRole::class)->handle($other, AdminRole::Owner, $almostOwner))->toThrow(RoleEscalationException::class, 'Owner');

    app(ChangeAdminRole::class)->handle($other, AdminRole::Owner, $owner);

    expect(fn () => app(ChangeAdminRole::class)->handle($other, AdminRole::Admin, $almostOwner))->toThrow(RoleEscalationException::class, 'Owner');
});

it('does not let anyone change their own role or the permissions of their own role', function () {
    $role = Role::factory()->withPermissions([Ability::RolesManage, Ability::AdminsManage, Ability::ProjectsView])->create();
    $user = User::factory()->create();
    $role->assignTo($user);
    $user->save();

    expect(fn () => app(ChangeAdminRole::class)->handle($user, AdminRole::Auditor, $user))->toThrow(RoleEscalationException::class)
        ->and(fn () => app(SaveRole::class)->handle($role, $role->name, [Ability::RolesManage, Ability::AdminsManage], [], $user))->toThrow(RoleEscalationException::class);

    app(SaveRole::class)->handle($role, $role->name, [Ability::RolesManage, Ability::AdminsManage, Ability::ProjectsView], ['group-a'], $user);

    expect($role->fresh()->sso_groups)->toBe(['group-a']);
});

it('does not delete a role that is in use or a system role', function () {
    $owner = User::factory()->create();
    $role = Role::factory()->create();
    $holder = User::factory()->create();
    $role->assignTo($holder);
    $holder->save();

    expect(fn () => app(DeleteRole::class)->handle($role, $owner))->toThrow(RoleEscalationException::class, 'in use');

    app(ChangeAdminRole::class)->handle($holder, AdminRole::Auditor, $owner);
    app(DeleteRole::class)->handle($role, $owner);

    expect(Role::query()->whereKey($role->id)->exists())->toBeFalse()
        ->and(AuditEntry::query()->where('event', 'role_deleted')->count())->toBe(1);

    $invited = Role::factory()->create();
    app(InviteAdmin::class)->handle('x@example.test', $owner, $invited);

    expect(fn () => app(DeleteRole::class)->handle($invited, $owner))->toThrow(RoleEscalationException::class);
});

it('applies the role of an invitation, custom ones too', function () {
    $owner = User::factory()->create();
    $role = Role::factory()->withPermissions([Ability::LogsView])->create();

    $issued = app(InviteAdmin::class)->handle('new@example.test', $owner, $role);
    $user = app(AcceptInvitation::class)->handle(basename(parse_url($issued->url, PHP_URL_PATH)), 'New Person', 'a-long-password-123');

    expect($user->role)->toBe(AdminRole::Custom)->and($user->custom_role_id)->toBe($role->id)->and($user->can(Ability::LogsView))->toBeTrue();
});

it('maps identity provider groups to a custom role from the role itself', function () {
    $this->idp = (new FakeOidcProvider)->install(['vault.sso.auto_provision' => true]);
    $role = Role::factory()->withPermissions([Ability::LogsView])->create(['sso_groups' => ['log-readers']]);

    finishSso(['groups' => ['log-readers'], 'amr' => ['mfa']]);

    $user = User::query()->sole();

    expect($user->role)->toBe(AdminRole::Custom)->and($user->custom_role_id)->toBe($role->id);
});

it('lets the environment override the groups of a system role and the page set the others', function () {
    $this->idp = (new FakeOidcProvider)->install();
    Role::forAdminRole(AdminRole::Admin)->forceFill(['sso_groups' => ['from-page']])->save();

    $sso = app(SsoConfig::class);
    $groups = fn (): array => collect($sso->roleGroups())->mapWithKeys(fn ($row) => [$row['role']->slug => $row['groups']])->all();

    expect($groups()['admin'])->toBe(['from-page']);

    config(['vault.sso.role_groups.admin' => 'from-env']);

    expect($groups()['admin'])->toBe(['from-env']);
});

it('runs the roles page: create, edit, refuse escalation and delete', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create());

    $component = Livewire::test('pages::admins.roles')
        ->set('name', 'Readers')
        ->set('permissions', [Ability::ProjectsView, Ability::LogsView])
        ->set('ssoGroups', 'readers, auditors-eu')
        ->call('save')
        ->assertHasNoErrors();

    $role = Role::query()->where('slug', 'readers')->sole();

    expect($role->permissions)->toBe([Ability::ProjectsView, Ability::LogsView])->and($role->sso_groups)->toBe(['readers', 'auditors-eu']);

    $component->call('delete')->assertHasNoErrors();

    expect(Role::query()->where('slug', 'readers')->exists())->toBeFalse();

    $this->actingAs(userWithPermissions([Ability::RolesManage, Ability::ProjectsView]));
    Livewire::test('pages::admins.roles')->set('name', 'Too much')->set('permissions', [Ability::SettingsSso])->call('save')->assertHasErrors('permissions');
});

it('limits the role choices on the admins page to what the admin may give', function () {
    $custom = Role::factory()->withPermissions([Ability::SettingsSso])->create(['name' => 'Single sign-on admins']);
    $hr = userWithPermissions([Ability::AdminsManage, Ability::AdminsView, Ability::ProjectsView]);
    $this->actingAs($hr);

    $roles = Livewire::test('pages::admins.index')->get('assignableRoles')->pluck('slug')->all();

    expect($roles)->not->toContain('owner', 'admin', $custom->slug);

    $this->actingAs(User::factory()->withTwoFactor()->create());

    expect(Livewire::test('pages::admins.index')->get('assignableRoles')->pluck('slug')->all())->toContain('owner', 'admin', 'auditor', $custom->slug);
});

it('keeps every existing user at the access they had before roles became editable', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->admin()->create();
    $auditor = User::factory()->auditor()->create();

    expect($owner->can(Ability::AdminsManage))->toBeTrue()
        ->and($admin->can(Ability::ProjectsManage))->toBeTrue()->and($admin->can(Ability::AdminsManage))->toBeFalse()->and($admin->can(Ability::SettingsOrganisation))->toBeFalse()
        ->and($auditor->can(Ability::LogsExport))->toBeTrue()->and($auditor->can(Ability::TokensManage))->toBeFalse();
});
