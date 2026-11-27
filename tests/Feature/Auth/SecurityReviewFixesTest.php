<?php

use App\Models\User;
use App\Vault\Health\CspEnforcedCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Passkeys\Passkeys;

function addReviewPasskey(User $user): void
{
    DB::table('passkeys')->insert([
        'user_id' => $user->id,
        'name' => 'Security key',
        'credential_id' => 'review-credential-'.$user->id,
        'credential' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('does not let a password login through for an admin whose only second factor is a passkey', function () {
    $user = User::factory()->create();
    addReviewPasskey($user);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('still challenges an admin with an authenticator app and a passkey', function () {
    $user = User::factory()->withTwoFactor()->create();
    addReviewPasskey($user);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
});

it('never sets a remember me cookie', function () {
    $user = User::factory()->withTwoFactor()->create(['two_factor_secret' => null, 'two_factor_confirmed_at' => null]);

    $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => '1']);

    $response->assertSessionHasNoErrors();
    expect(collect($response->headers->getCookies())->contains(fn ($cookie): bool => str_starts_with($cookie->getName(), 'remember_web_')))->toBeFalse();
});

it('offers no remember me option on the login page', function () {
    $this->get(route('login'))->assertOk()->assertDontSee('Remember me');
});

it('does not allow a deactivated admin to log in with a passkey', function () {
    $active = User::factory()->withTwoFactor()->create();
    $deactivated = User::factory()->withTwoFactor()->create(['deactivated_at' => now()]);
    addReviewPasskey($active);
    addReviewPasskey($deactivated);

    $allows = fn (User $user): bool => Passkeys::allowsLogin(Request::create('/'), Passkeys::passkeyModel()::query()->where('user_id', $user->id)->firstOrFail());

    expect($allows($active))->toBeTrue()->and($allows($deactivated))->toBeFalse();
});

it('fails the csp check in production only when the policy is report only', function () {
    expect(app(CspEnforcedCheck::class)->run()->status->value)->toBe('ok');

    $this->app->detectEnvironment(fn (): string => 'production');
    config(['csp.report_only_presets' => []]);
    expect(app(CspEnforcedCheck::class)->run()->status->value)->toBe('ok');

    config(['csp.report_only_presets' => ['SomePolicy']]);
    expect(app(CspEnforcedCheck::class)->run()->status->value)->toBe('failed');
});

it('does not serve files from the local disk through a route', function () {
    expect(config('filesystems.disks.local.serve'))->toBeFalse();

    $this->get('/storage/anything.txt')->assertNotFound();
});
