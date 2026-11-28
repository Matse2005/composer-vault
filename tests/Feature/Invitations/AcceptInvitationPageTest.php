<?php

use App\Actions\Vault\InviteAdmin;
use App\Actions\Vault\RevokeInvitation;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

const INVALID_INVITATION_MESSAGE = 'This invitation is invalid or has expired.';

/**
 * @return array{Invitation, string}
 */
function invitationWithToken(string $email = 'new.admin@example.com'): array
{
    $inviter = User::factory()->create();
    $issued = app(InviteAdmin::class)->handle($email, $inviter);
    $inviter->delete();

    return [$issued->invitation, basename(parse_url($issued->url, PHP_URL_PATH))];
}

function invitationPage(string $token): TestResponse
{
    return test()->get(route('invitations.accept', $token));
}

it('shows the invitation form with the email address as read-only', function () {
    [, $token] = invitationWithToken('new.admin@example.com');

    $response = invitationPage($token);

    $response->assertOk()
        ->assertSee('new.admin@example.com')
        ->assertSee('name="name"', false)
        ->assertSee('type="password"', false);
    expect($response->getContent())->toMatch('/<input[^>]*(readonly|disabled)[^>]*new\.admin@example\.com|new\.admin@example\.com[^>]*(readonly|disabled)/');
});

it('creates the admin, logs in and redirects to the security page', function () {
    [$invitation, $token] = invitationWithToken('new.admin@example.com');
    $sessionIdBefore = session()->getId();

    Livewire::test('pages::auth.accept-invitation', ['token' => $token])
        ->set('name', 'New Admin')
        ->set('password', 'a-Strong-pass-123')
        ->set('password_confirmation', 'a-Strong-pass-123')
        ->call('accept')
        ->assertHasNoErrors()
        ->assertRedirect(route('security.edit'));

    $user = User::query()->where('email', 'new.admin@example.com')->sole();

    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('New Admin')
        ->and($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and(session()->getId())->not->toBe($sessionIdBefore);
});

it('sends the new admin on to set up a second factor', function () {
    [, $token] = invitationWithToken();

    Livewire::test('pages::auth.accept-invitation', ['token' => $token])
        ->set('name', 'New Admin')
        ->set('password', 'a-Strong-pass-123')
        ->set('password_confirmation', 'a-Strong-pass-123')
        ->call('accept');

    $this->get(route('dashboard'))->assertRedirect(route('security.edit'));
});

it('rejects a password that is not confirmed or a missing name and creates no user', function (string $name, string $password, string $confirmation, string $field) {
    [$invitation, $token] = invitationWithToken();

    Livewire::test('pages::auth.accept-invitation', ['token' => $token])
        ->set('name', $name)
        ->set('password', $password)
        ->set('password_confirmation', $confirmation)
        ->call('accept')
        ->assertHasErrors([$field]);

    expect(User::query()->count())->toBe(0)
        ->and($invitation->fresh()->isUsable())->toBeTrue();
})->with([
    'not confirmed' => ['New Admin', 'a-Strong-pass-123', 'different-pass-123', 'password'],
    'too short' => ['New Admin', 'short', 'short', 'password'],
    'no name' => ['', 'a-Strong-pass-123', 'a-Strong-pass-123', 'name'],
]);

it('shows the identical page with a 404 for every invalid state', function () {
    [$expired, $expiredToken] = invitationWithToken('expired@example.com');
    $expired->forceFill(['expires_at' => now()->subMinute()])->save();
    [$revoked, $revokedToken] = invitationWithToken('revoked@example.com');
    asOwner(fn () => app(RevokeInvitation::class)->handle($revoked));
    [$accepted, $acceptedToken] = invitationWithToken('accepted@example.com');
    $accepted->forceFill(['accepted_at' => now()])->save();

    $responses = collect([
        'unknown' => invitationPage(str_repeat('a', 48)),
        'malformed' => invitationPage('short'),
        'expired' => invitationPage($expiredToken),
        'revoked' => invitationPage($revokedToken),
        'accepted' => invitationPage($acceptedToken),
    ]);

    // Vite only emits its font preload tags on the first response of an app instance, and every response carries its
    // own Content Security Policy nonce. Neither has anything to do with the invitation, so they are left out.
    $withoutPreloads = fn (TestResponse $response): string => preg_replace(
        ['/<link rel="preload"[^>]*>\s*/', '/ nonce="[^"]*"/'],
        ['', ''],
        $response->getContent(),
    );

    foreach ($responses as $state => $response) {
        $response->assertNotFound()->assertSee(INVALID_INVITATION_MESSAGE);
        expect($withoutPreloads($response))->toBe($withoutPreloads($responses['unknown']), "state: {$state}")
            ->not->toContain('@example.com');
    }
});

it('refuses an accept action on an invitation that became invalid and creates no user', function () {
    [$invitation, $token] = invitationWithToken();
    $component = Livewire::test('pages::auth.accept-invitation', ['token' => $token]);

    asOwner(fn () => app(RevokeInvitation::class)->handle($invitation));

    $component->set('name', 'New Admin')
        ->set('password', 'a-Strong-pass-123')
        ->set('password_confirmation', 'a-Strong-pass-123')
        ->call('accept')
        ->assertSee(INVALID_INVITATION_MESSAGE)
        ->assertNoRedirect();

    expect(User::query()->count())->toBe(0);
    $this->assertGuest();
});

it('cannot be used twice from the page', function () {
    [, $token] = invitationWithToken();

    Livewire::test('pages::auth.accept-invitation', ['token' => $token])
        ->set('name', 'First')
        ->set('password', 'a-Strong-pass-123')
        ->set('password_confirmation', 'a-Strong-pass-123')
        ->call('accept');
    auth()->logout();

    invitationPage($token)->assertNotFound()->assertSee(INVALID_INVITATION_MESSAGE);
    expect(User::query()->count())->toBe(1);
});

it('is only for guests', function () {
    [, $token] = invitationWithToken();
    $this->actingAs(User::factory()->withTwoFactor()->create());

    invitationPage($token)->assertRedirect();
});

it('allows 10 requests a minute per address and then answers 429', function () {
    foreach (range(1, 10) as $attempt) {
        invitationPage(str_repeat('a', 48))->assertNotFound();
    }

    invitationPage(str_repeat('a', 48))->assertStatus(429);
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->get(route('invitations.accept', str_repeat('a', 48)))->assertNotFound();
});
