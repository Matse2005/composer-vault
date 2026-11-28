<?php

use App\Actions\Vault\DeactivateAdmin;
use App\Actions\Vault\ReactivateAdmin;
use App\Enums\AdminRole;
use App\Models\AuditEntry;
use App\Models\Invitation;
use App\Models\User;
use App\Vault\Settings\VaultSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Events\Login;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('revokes the open invitations of a deactivated admin and leaves the others', function () {
    $owner = User::factory()->create();
    $leaver = User::factory()->admin()->create();
    $open = Invitation::factory()->create(['invited_by' => $leaver->id]);
    $accepted = Invitation::factory()->create(['invited_by' => $leaver->id, 'accepted_at' => now()]);
    $other = Invitation::factory()->create(['invited_by' => $owner->id]);

    app(DeactivateAdmin::class)->handle($leaver, $owner);

    expect($open->fresh()->revoked_at)->not->toBeNull()
        ->and($accepted->fresh()->revoked_at)->toBeNull()
        ->and($other->fresh()->revoked_at)->toBeNull()
        ->and(AuditEntry::query()->where('event', 'admin_deactivated')->sole()->properties['revoked_invitations'])->toBe(1);
});

it('reactivates a deactivated admin, audits it and restarts the inactivity clock', function () {
    $owner = User::factory()->create();
    $gone = User::factory()->admin()->create(['deactivated_at' => now()->subDay(), 'last_login_at' => now()->subYear()]);

    app(ReactivateAdmin::class)->handle($gone, $owner);

    expect($gone->fresh()->deactivated_at)->toBeNull()
        ->and($gone->fresh()->last_login_at->isToday())->toBeTrue()
        ->and(AuditEntry::query()->where('event', 'admin_reactivated')->sole()->causer_id)->toBe($owner->id);
});

it('does nothing when reactivating an active admin and refuses non-owners', function () {
    $owner = User::factory()->create();
    app(ReactivateAdmin::class)->handle(User::factory()->create(), $owner);

    expect(AuditEntry::query()->count())->toBe(0)
        ->and(fn () => app(ReactivateAdmin::class)->handle(User::factory()->create(['deactivated_at' => now()]), User::factory()->admin()->create()))
        ->toThrow(AuthorizationException::class);
});

it('reactivates from the admins page', function () {
    $owner = User::factory()->withTwoFactor()->create();
    $gone = User::factory()->withTwoFactor()->create(['deactivated_at' => now()]);
    $this->actingAs($owner);

    Livewire::test('pages::admins.index')->assertSee('Reactivate')->call('reactivate', $gone->id);

    expect($gone->fresh()->deactivated_at)->toBeNull();
});

it('lets a reactivated admin log in again by password', function () {
    $user = User::factory()->withTwoFactor()->create(['email' => 'back@example.test', 'deactivated_at' => now()]);
    $this->post(route('login.store'), ['email' => 'back@example.test', 'password' => 'password'])->assertSessionHasErrors('email');

    app(ReactivateAdmin::class)->handle($user, User::factory()->create());

    $this->post(route('login.store'), ['email' => 'back@example.test', 'password' => 'password'])->assertRedirect(route('two-factor.login'));
});

it('ends a session after the absolute lifetime however active it is', function () {
    config(['vault.admin.session_absolute_hours' => 2]);
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user)->withSession([]);

    $this->get(route('projects.index'))->assertOk();

    $this->travel(119)->minutes();
    $this->get(route('projects.index'))->assertOk();

    $this->travel(2)->minutes();
    $this->get(route('projects.index'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('uses twelve hours unless changed in the dashboard', function () {
    expect(app(VaultSettings::class)->sessionAbsoluteHours())->toBe(12);

    $this->actingAs(User::factory()->withTwoFactor()->create());
    $this->get(route('projects.index'))->assertOk();

    $this->travel(11)->hours();
    $this->get(route('projects.index'))->assertOk();

    $this->travel(2)->hours();
    $this->get(route('projects.index'))->assertRedirect(route('login'));
});

it('starts the absolute lifetime at the login', function () {
    $user = User::factory()->withTwoFactor()->create(['email' => 'timer@example.test']);

    $this->travel(5)->hours();
    event(new Login('web', $user, false));
    $this->actingAs($user);

    $this->travel(11)->hours();
    $this->get(route('projects.index'))->assertOk();
});

it('deactivates the admins who have been inactive too long and skips the protected ones', function () {
    config(['vault.admin.inactive_deactivate_days' => 30, 'session.driver' => 'database']);
    $fresh = User::factory()->admin()->create(['last_login_at' => now()->subDays(5)]);
    $stale = User::factory()->admin()->create(['last_login_at' => now()->subDays(31)]);
    $neverLoggedIn = User::factory()->admin()->create(['created_at' => now()->subDays(40), 'last_login_at' => null]);
    $newcomer = User::factory()->admin()->create(['last_login_at' => null]);
    $breakGlass = User::factory()->create(['last_login_at' => now()->subDays(90), 'is_break_glass' => true]);
    $alreadyGone = User::factory()->admin()->create(['last_login_at' => now()->subDays(99), 'deactivated_at' => now()->subDay()]);
    DB::table('sessions')->insert(['id' => 's1', 'user_id' => $stale->id, 'ip_address' => '127.0.0.1', 'user_agent' => 't', 'payload' => '', 'last_activity' => time()]);

    $this->artisan('vault:deactivate-inactive-admins')->assertSuccessful();

    expect($fresh->fresh()->isDeactivated())->toBeFalse()
        ->and($stale->fresh()->isDeactivated())->toBeTrue()
        ->and($neverLoggedIn->fresh()->isDeactivated())->toBeTrue()
        ->and($newcomer->fresh()->isDeactivated())->toBeFalse()
        ->and($breakGlass->fresh()->isDeactivated())->toBeFalse()
        ->and($alreadyGone->fresh()->deactivated_at)->not->toBeNull()
        ->and(DB::table('sessions')->count())->toBe(0);

    $entries = AuditEntry::query()->where('event', 'admin_deactivated')->get();

    expect($entries)->toHaveCount(2)
        ->and($entries->every(fn ($entry) => $entry->causer_id === null && $entry->properties['source'] === 'schedule'))->toBeTrue();
});

it('skips the last active owner even when inactive', function () {
    config(['vault.admin.inactive_deactivate_days' => 30]);
    $only = User::factory()->create(['last_login_at' => now()->subDays(99)]);
    User::factory()->admin()->create(['last_login_at' => now()]);

    $this->artisan('vault:deactivate-inactive-admins')->assertSuccessful();

    expect($only->fresh()->isDeactivated())->toBeFalse();
});

it('deactivates an owner for inactivity while another active owner remains', function () {
    config(['vault.admin.inactive_deactivate_days' => 30]);
    $stale = User::factory()->create(['last_login_at' => now()->subDays(60)]);
    User::factory()->create(['last_login_at' => now()]);

    $this->artisan('vault:deactivate-inactive-admins')->assertSuccessful();

    expect($stale->fresh()->isDeactivated())->toBeTrue();
});

it('is scheduled every day', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->command, 'vault:deactivate-inactive-admins'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('0 0 * * *');
});

it('saves the access policy from the page and refuses values out of range', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create());

    Livewire::test('pages::settings.access')
        ->set('sessionAbsoluteHours', '8')
        ->set('inactiveDeactivateDays', '60')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(VaultSettings::class)->sessionAbsoluteHours())->toBe(8)
        ->and(app(VaultSettings::class)->inactiveDeactivateDays())->toBe(60);

    Livewire::test('pages::settings.access')->set('sessionAbsoluteHours', '0')->set('inactiveDeactivateDays', '3')->call('save')
        ->assertHasErrors(['sessionAbsoluteHours', 'inactiveDeactivateDays']);

    $this->actingAs(User::factory()->withTwoFactor()->create(['role' => AdminRole::Admin]));
    $this->get(route('settings.access'))->assertForbidden();
});

it('locks a field that the environment sets', function () {
    config(['vault.admin.session_absolute_hours' => 3]);
    $this->actingAs(User::factory()->withTwoFactor()->create());

    Livewire::test('pages::settings.access')->assertSee('Set by the environment')->set('sessionAbsoluteHours', '9')->call('save');

    expect(app(VaultSettings::class)->sessionAbsoluteHours())->toBe(3);
});
