<?php

use App\Http\Middleware\EnsureSecondFactor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

const SECOND_FACTOR_MESSAGE = 'Set up two-factor authentication or a passkey to continue.';

function addPasskey(User $user): void
{
    DB::table('passkeys')->insert([
        'user_id' => $user->id,
        'name' => 'Security key',
        'credential_id' => 'credential-'.$user->id,
        'credential' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Confirm the password in the session, as Fortify does after the confirmation form.
 */
function withConfirmedPassword(): void
{
    test()->withSession(['auth.password_confirmed_at' => time()]);
}

it('has no second factor without a confirmed authenticator and without a passkey', function () {
    $plain = User::factory()->create();
    $pending = User::factory()->create(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => null]);

    expect($plain->hasSecondFactor())->toBeFalse()
        ->and($pending->hasSecondFactor())->toBeFalse();
});

it('has a second factor with a confirmed authenticator or with a passkey', function () {
    $totp = User::factory()->withTwoFactor()->create();
    $passkeyOnly = User::factory()->create();
    addPasskey($passkeyOnly);

    expect($totp->hasSecondFactor())->toBeTrue()
        ->and($passkeyOnly->hasSecondFactor())->toBeTrue();
});

it('redirects a user without a second factor from the dashboard to the security page', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHas('second_factor_required', SECOND_FACTOR_MESSAGE);
});

it('redirects a user without a second factor from every protected page', function (string $routeName) {
    $this->actingAs(User::factory()->create());

    $this->get(route($routeName))->assertRedirect(route('security.edit'));
})->with(['dashboard', 'profile.edit', 'appearance.edit']);

it('redirects a Livewire update request from a protected component', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user);

    $html = $this->get(route('profile.edit'))->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);
    $snapshot = html_entity_decode($matches[1], ENT_QUOTES);

    $user->forceFill(['two_factor_confirmed_at' => null, 'two_factor_secret' => null])->save();

    $response = $this->postJson(route('default-livewire.update'), [
        '_token' => csrf_token(),
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => ['name' => 'Changed Name'],
            'calls' => [['path' => '', 'method' => 'updateProfileInformation', 'params' => []]],
        ]],
    ], ['X-Livewire' => 'true']);

    expect($user->fresh()->name)->not->toBe('Changed Name')
        ->and($response->getStatusCode())->not->toBe(200)
        ->and($response->getContent())->not->toContain('Changed Name');
});

it('lets a user without a second factor open the security page', function () {
    $this->actingAs(User::factory()->create());
    withConfirmedPassword();

    $this->get(route('security.edit'))->assertOk()->assertSee(SECOND_FACTOR_MESSAGE);
});

it('lets a user without a second factor confirm a password and log out', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('password.confirm'))->assertOk();
    $this->post(route('password.confirm.store'), ['password' => 'password'])->assertRedirect();

    $this->post(route('logout'))->assertRedirect();
    $this->assertGuest();
});

it('lets a user without a second factor use the two-factor and passkey setup routes', function (string $method, string $routeName, array $parameters) {
    $this->actingAs(User::factory()->create());
    withConfirmedPassword();

    $response = $this->call($method, route($routeName, $parameters));

    expect($response->isRedirect() && str_contains((string) $response->headers->get('Location'), route('security.edit')) && session('second_factor_required') !== null)->toBeFalse();
})->with([
    'enable two-factor' => ['POST', 'two-factor.enable', []],
    'qr code' => ['GET', 'two-factor.qr-code', []],
    'passkey registration options' => ['GET', 'passkey.registration-options', []],
    'verification notice' => ['GET', 'verification.notice', []],
]);

it('lets a user with a confirmed authenticator in', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create());

    $this->get(route('dashboard'))->assertOk();
    $this->get(route('profile.edit'))->assertOk();
});

it('lets a user with only a passkey in', function () {
    $user = User::factory()->create();
    addPasskey($user);
    $this->actingAs($user);

    $this->get(route('dashboard'))->assertOk();
});

it('redirects a user again after the authenticator is disabled and no passkey is left', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user);

    $this->get(route('dashboard'))->assertOk();

    $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

    $this->get(route('dashboard'))->assertRedirect(route('security.edit'));
});

it('keeps a user with a passkey in after the authenticator is disabled', function () {
    $user = User::factory()->withTwoFactor()->create();
    addPasskey($user);
    $this->actingAs($user);

    $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();

    $this->get(route('dashboard'))->assertOk();
});

it('leaves guests and the login page alone', function () {
    $this->get(route('login'))->assertOk();
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('registers the middleware as a persistent Livewire middleware', function () {
    expect(Livewire::getPersistentMiddleware())->toContain(EnsureSecondFactor::class);
});
