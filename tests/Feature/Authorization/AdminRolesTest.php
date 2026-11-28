<?php

use App\Actions\Vault\AcceptInvitation;
use App\Actions\Vault\ChangeAdminRole;
use App\Actions\Vault\DeactivateAdmin;
use App\Actions\Vault\InviteAdmin;
use App\Enums\AdminRole;
use App\Exceptions\Vault\LastOwnerException;
use App\Exceptions\Vault\RoleEscalationException;
use App\Models\AuditEntry;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\User;
use App\Vault\Authorization\OwnerGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

it('gives a role without the capability a 403 on the page', function (string $route, string $role, int $status) {
    $this->actingAs(User::factory()->withTwoFactor()->create(['role' => AdminRole::from($role)]));

    $parameters = in_array($route, ['credentials.create', 'tokens.create'], true) ? [Project::factory()->create()] : [];

    $this->get(route($route, $parameters))->assertStatus($status);
})->with([
    'admins as owner' => ['admins.index', 'owner', 200],
    'admins as admin' => ['admins.index', 'admin', 403],
    'admins as auditor' => ['admins.index', 'auditor', 403],
    'organisation as admin' => ['settings.organisation', 'admin', 403],
    'organisation as auditor' => ['settings.organisation', 'auditor', 403],
    'create project as auditor' => ['projects.create', 'auditor', 403],
    'create project as admin' => ['projects.create', 'admin', 200],
    'create credential as auditor' => ['credentials.create', 'auditor', 403],
    'create token as auditor' => ['tokens.create', 'auditor', 403],
    'projects list as auditor' => ['projects.index', 'auditor', 200],
    'access log as auditor' => ['logs.access', 'auditor', 200],
    'audit log as auditor' => ['logs.audit', 'auditor', 200],
    'security status as auditor' => ['security-status', 'auditor', 200],
]);

it('refuses a page of the owner when the request is made by a role without the capability', function () {
    $project = Project::factory()->create();
    $this->actingAs(User::factory()->withTwoFactor()->auditor()->create());

    $this->get(route('credentials.create', $project))->assertForbidden();
});

it('hides what a role cannot use from the list and the sidebar', function () {
    Project::factory()->create(['name' => 'Hidden link project']);
    $this->actingAs(User::factory()->withTwoFactor()->auditor()->create());

    $this->get(route('projects.index'))->assertOk()->assertSee('Hidden link project')
        ->assertDontSee('New project')->assertDontSee(route('admins.index'))->assertDontSee(route('settings.organisation'));
});

it('shows an owner every link', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create());

    $this->get(route('projects.index'))->assertOk()->assertSee('New project')->assertSee(route('admins.index'));
});

it('changes a role and audits the old and new role', function () {
    $owner = User::factory()->create();
    $other = User::factory()->admin()->create();

    app(ChangeAdminRole::class)->handle($other, AdminRole::Auditor, $owner);

    $entry = AuditEntry::query()->where('event', 'admin_role_changed')->sole();

    expect($other->fresh()->role)->toBe(AdminRole::Auditor)
        ->and($entry->properties->toArray())->toEqual(['old_role' => 'admin', 'new_role' => 'auditor'])
        ->and($entry->causer_id)->toBe($owner->id);
});

it('writes no audit entry when the role stays the same', function () {
    $owner = User::factory()->create();

    app(ChangeAdminRole::class)->handle(User::factory()->admin()->create(), AdminRole::Admin, $owner);

    expect(AuditEntry::query()->count())->toBe(0);
});

it('does not let anyone change their own role, so the last owner cannot demote themselves', function () {
    $onlyOwner = User::factory()->create();

    expect(fn () => app(ChangeAdminRole::class)->handle($onlyOwner, AdminRole::Admin, $onlyOwner))
        ->toThrow(RoleEscalationException::class)
        ->and($onlyOwner->fresh()->role)->toBe(AdminRole::Owner);
});

it('allows demoting an owner while another active owner remains', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();

    app(ChangeAdminRole::class)->handle($first, AdminRole::Admin, $second);

    expect($first->fresh()->role)->toBe(AdminRole::Admin);
});

it('does not count a deactivated owner as another owner', function () {
    $owner = User::factory()->create();
    User::factory()->create(['deactivated_at' => now()]);

    expect(fn () => OwnerGuard::assertAnotherActiveOwnerExists($owner))->toThrow(LastOwnerException::class);
});

it('does not guard admins who are not owners', function () {
    OwnerGuard::assertAnotherActiveOwnerExists(User::factory()->admin()->create());

    expect(true)->toBeTrue();
});

it('refuses to let an admin or an auditor deactivate anyone', function (string $role) {
    $actor = User::factory()->create(['role' => AdminRole::from($role)]);
    $owner = User::factory()->create();

    expect(fn () => app(DeactivateAdmin::class)->handle($owner, $actor))->toThrow(AuthorizationException::class);
})->with(['admin', 'auditor']);

it('applies the invited role when the invitation is accepted', function (AdminRole $role) {
    $owner = User::factory()->create();
    $issued = app(InviteAdmin::class)->handle('new@example.test', $owner, $role);

    $user = app(AcceptInvitation::class)->handle(basename(parse_url($issued->url, PHP_URL_PATH)), 'New Person', 'a-long-password-123');

    expect($user->role)->toBe($role)->and($issued->invitation->role)->toBe($role);
})->with([AdminRole::Owner, AdminRole::Admin, AdminRole::Auditor]);

it('invites with the admin role unless told otherwise', function () {
    $issued = app(InviteAdmin::class)->handle('new@example.test', User::factory()->create());

    expect($issued->invitation->role)->toBe(AdminRole::Admin);
});

it('lets the first owner invitation of an install through only when no admin exists', function () {
    $issued = app(InviteAdmin::class)->handleFirstOwner('first@example.test');

    expect($issued->invitation->role)->toBe(AdminRole::Owner);

    User::factory()->create();

    expect(fn () => app(InviteAdmin::class)->handleFirstOwner('second@example.test'))->toThrow(AuthorizationException::class);
});

it('makes every admin that exists before roles an owner when migrating', function () {
    $migration = require database_path('migrations/2026_11_28_010000_add_role_to_users_table.php');

    $migration->down();
    expect(Schema::hasColumn('users', 'role'))->toBeFalse();

    DB::table('users')->insert([
        ['name' => 'Old One', 'email' => 'one@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'Old Two', 'email' => 'two@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $migration->up();

    expect(DB::table('users')->pluck('role')->unique()->values()->all())->toBe(['owner']);
});

it('changes the role from the admins page and shows the refusal for the last owner', function () {
    $owner = User::factory()->withTwoFactor()->create();
    $other = User::factory()->withTwoFactor()->admin()->create();
    $this->actingAs($owner);

    Livewire::test('pages::admins.index')->call('changeRole', $other->id, 'auditor')->assertHasNoErrors();

    expect($other->fresh()->role)->toBe(AdminRole::Auditor);

    $other->forceFill(['deactivated_at' => now()])->save();

    Livewire::test('pages::admins.index')->call('changeRole', $owner->id, 'admin')->assertHasErrors('deactivate');

    expect($owner->fresh()->role)->toBe(AdminRole::Owner);
});

it('invites with a role from the admins page', function () {
    config(['mail.default' => 'log']);
    $this->actingAs(User::factory()->withTwoFactor()->create());

    Livewire::test('pages::admins.index')
        ->set('email', 'auditor@example.test')
        ->set('role', 'auditor')
        ->call('invite')
        ->assertHasNoErrors();

    expect(Invitation::query()->where('email', 'auditor@example.test')->sole()->role)->toBe(AdminRole::Auditor);
});
