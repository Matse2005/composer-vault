<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Vault\AcceptInvitation;
use App\Actions\Vault\ChangeAdminRole;
use App\Actions\Vault\InviteAdmin;
use App\Actions\Vault\SetBreakGlass;
use App\Enums\AdminRole;
use App\Exceptions\Vault\BreakGlassNotAllowedException;
use App\Exceptions\Vault\InvalidInvitationException;
use App\Models\AuditEntry;
use App\Models\Invitation;
use App\Models\User;
use App\Vault\Health\SsoConfigurationCheck;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Passkeys;
use Livewire\Livewire;
use Tests\Support\FakeOidcProvider;

beforeEach(function () {
    $this->idp = (new FakeOidcProvider)->install(['vault.sso.enforce' => true]);
});

it('refuses the password login for a normal admin and allows it for break-glass', function () {
    $normal = User::factory()->withTwoFactor()->admin()->create(['email' => 'normal@example.test']);
    $glass = User::factory()->withTwoFactor()->create(['email' => 'glass@example.test', 'is_break_glass' => true]);

    $this->post(route('login.store'), ['email' => 'normal@example.test', 'password' => 'password'])->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->post(route('login.store'), ['email' => 'glass@example.test', 'password' => 'password'])->assertRedirect(route('two-factor.login'));
    expect(session('login.id'))->toBe($glass->id)->and($normal->id)->not->toBe($glass->id);
});

it('allows the password login again when enforcement is off', function () {
    config(['vault.sso.enforce' => false]);
    User::factory()->withTwoFactor()->admin()->create(['email' => 'normal@example.test']);

    $this->post(route('login.store'), ['email' => 'normal@example.test', 'password' => 'password'])->assertRedirect(route('two-factor.login'));
});

it('refuses a passkey login for a normal admin', function () {
    $normal = User::factory()->withTwoFactor()->admin()->create();
    $glass = User::factory()->withTwoFactor()->create(['is_break_glass' => true]);
    foreach ([$normal, $glass] as $user) {
        DB::table('passkeys')->insert(['user_id' => $user->id, 'name' => 'Key', 'credential_id' => 'cred-'.$user->id, 'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    }

    $allows = fn (User $user): bool => Passkeys::allowsLogin(Request::create('/'), Passkeys::passkeyModel()::query()->where('user_id', $user->id)->firstOrFail());

    expect($allows($normal))->toBeFalse()->and($allows($glass))->toBeTrue();
});

it('refuses a password reset link for a normal admin without telling, and sends one to break-glass', function () {
    Notification::fake();
    $normal = User::factory()->admin()->create(['email' => 'normal@example.test']);
    $glass = User::factory()->create(['email' => 'glass@example.test', 'is_break_glass' => true]);

    $this->post(route('password.email'), ['email' => 'normal@example.test'])->assertSessionHas('status');
    $this->post(route('password.email'), ['email' => 'nobody@example.test'])->assertSessionHas('status');
    Notification::assertNothingSent();

    $this->post(route('password.email'), ['email' => 'glass@example.test']);
    Notification::assertSentTo($glass, ResetPassword::class);
    Notification::assertNotSentTo($normal, ResetPassword::class);
});

it('refuses the reset itself for a normal admin', function () {
    $normal = User::factory()->admin()->create();

    expect(fn () => app(ResetUserPassword::class)->reset($normal, ['password' => 'A-long-Passw0rd!', 'password_confirmation' => 'A-long-Passw0rd!']))
        ->toThrow(ValidationException::class);
});

it('does not let an invitation be accepted with a password, but pre-creates the admin at the first sign-in', function () {
    $owner = User::factory()->create();
    $issued = app(InviteAdmin::class)->handle('person@example.test', $owner, AdminRole::Auditor);
    $token = basename(parse_url($issued->url, PHP_URL_PATH));

    expect(fn () => app(AcceptInvitation::class)->handle($token, 'Person', 'a-long-password-123'))->toThrow(InvalidInvitationException::class);
    $this->get(route('invitations.accept', $token))->assertRedirect(route('login'));

    $this->flushSession();
    finishSso(['amr' => ['mfa']]);

    $user = User::query()->where('email', 'person@example.test')->firstOrFail();

    expect($user->role)->toBe(AdminRole::Auditor)
        ->and($user->sso_subject)->toBe('subject-1')
        ->and(Invitation::query()->sole()->accepted_at)->not->toBeNull()
        ->and(AuditEntry::query()->where('event', 'admin_provisioned')->sole()->properties['source'])->toBe('invitation');
});

it('keeps the install invitation of the first owner acceptable and makes that owner break-glass', function () {
    $issued = app(InviteAdmin::class)->handleFirstOwner('first@example.test');

    $user = app(AcceptInvitation::class)->handle(basename(parse_url($issued->url, PHP_URL_PATH)), 'First', 'a-long-password-123');

    expect($user->is_break_glass)->toBeTrue()->and($user->role)->toBe(AdminRole::Owner);
});

it('marks at most two active owners as break-glass and audits each change', function () {
    $actor = User::factory()->create();
    [$one, $two, $three] = [User::factory()->create(), User::factory()->create(), User::factory()->create()];
    $action = app(SetBreakGlass::class);

    $action->handle($one, true, $actor);
    $action->handle($two, true, $actor);

    expect(fn () => $action->handle($three, true, $actor))->toThrow(BreakGlassNotAllowedException::class, 'At most two')
        ->and(AuditEntry::query()->where('event', 'break_glass_changed')->count())->toBe(2);

    $action->handle($one, false, $actor);
    $action->handle($three, true, $actor);

    expect($three->fresh()->is_break_glass)->toBeTrue();
});

it('only marks an Owner and only for an Owner to do', function () {
    $owner = User::factory()->create();

    expect(fn () => app(SetBreakGlass::class)->handle(User::factory()->admin()->create(), true, $owner))->toThrow(BreakGlassNotAllowedException::class, 'Owner')
        ->and(fn () => app(SetBreakGlass::class)->handle($owner, true, User::factory()->admin()->create()))->toThrow(AuthorizationException::class);
});

it('drops the mark when the owner is demoted', function () {
    $actor = User::factory()->create();
    $glass = User::factory()->create(['is_break_glass' => true]);

    app(ChangeAdminRole::class)->handle($glass, AdminRole::Admin, $actor);

    expect($glass->fresh()->is_break_glass)->toBeFalse();
});

it('audits a local break-glass login and not a normal one', function () {
    $glass = User::factory()->create(['is_break_glass' => true]);
    $normal = User::factory()->create();

    event(new Login('web', $normal, false));
    expect(AuditEntry::query()->where('event', 'break_glass_login')->count())->toBe(0);

    event(new Login('web', $glass, false));
    $entry = AuditEntry::query()->where('event', 'break_glass_login')->sole();

    expect((int) $entry->subject_id)->toBe($glass->id);
});

it('marks break-glass from the admins page', function () {
    $owner = User::factory()->withTwoFactor()->create();
    $other = User::factory()->withTwoFactor()->create();
    $this->actingAs($owner);

    Livewire::test('pages::admins.index')->call('setBreakGlass', $other->id, true)->assertHasNoErrors();

    expect($other->fresh()->is_break_glass)->toBeTrue();

    Livewire::test('pages::admins.index')->call('setBreakGlass', $other->id, false);
    $other->forceFill(['role' => AdminRole::Admin])->save();

    Livewire::test('pages::admins.index')->call('setBreakGlass', $other->id, true)->assertHasErrors('deactivate');
});

it('reports the single sign-on health states', function () {
    $check = fn (): string => app(SsoConfigurationCheck::class)->run()->status->value;

    config(['vault.sso.enabled' => false]);
    expect($check())->toBe('ok');

    config(['vault.sso.enabled' => true, 'vault.sso.client_secret' => '']);
    expect($check())->toBe('failed');

    config(['vault.sso.client_secret' => 'secret']);
    $this->idp->metadata = [];
    Cache::flush();
    expect($check())->toBe('failed');

    $this->idp->metadata = (new FakeOidcProvider)->metadata;
    Cache::flush();
    expect($check())->toBe('warning');

    User::factory()->create(['is_break_glass' => true]);
    expect($check())->toBe('ok');

    foreach (range(1, 2) as $ignored) {
        User::factory()->create(['is_break_glass' => true]);
    }
    expect($check())->toBe('warning');

    config(['vault.sso.enforce' => false]);
    expect($check())->toBe('ok');
});

it('shows the break-glass note on the login page under enforcement', function () {
    $this->get(route('login'))->assertOk()->assertSee('Break-glass Owners can sign in below');
});
