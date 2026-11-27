<?php

use App\Models\AuditEntry;
use App\Models\User;
use App\Vault\Audit\AuditChainVerifier;
use Illuminate\Auth\Events\Login;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Passkey;

it('audits a real login with the admin as subject and actor', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect();

    $entry = AuditEntry::query()->sole();

    expect($entry->event)->toBe('admin_logged_in')
        ->and($entry->subject_type)->toBe(User::class)
        ->and($entry->subject_id)->toBe((string) $user->id)
        ->and((string) $entry->causer_id)->toBe((string) $user->id)
        ->and($entry->properties->toArray())->toBe([])
        ->and($entry->ip)->not->toBeNull();
});

it('does not audit a failed login', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);

    expect(AuditEntry::query()->count())->toBe(0);
});

it('audits confirmed and disabled two-factor authentication', function () {
    $user = User::factory()->create();

    TwoFactorAuthenticationConfirmed::dispatch($user);
    TwoFactorAuthenticationDisabled::dispatch($user);

    expect(AuditEntry::query()->orderBy('sequence')->pluck('event')->all())->toBe(['two_factor_enabled', 'two_factor_disabled']);
});

it('audits a passkey that was added and removed without storing its name', function () {
    $user = User::factory()->create();
    $passkey = (new Passkey)->forceFill(['id' => 7, 'name' => 'Personal laptop of Jane Doe']);

    PasskeyRegistered::dispatch($user, $passkey);
    PasskeyDeleted::dispatch($user, $passkey);

    $entries = AuditEntry::query()->orderBy('sequence')->get();

    expect($entries->pluck('event')->all())->toBe(['passkey_added', 'passkey_removed'])
        ->and($entries[0]->properties->toArray())->toBe(['passkey_id' => 7])
        ->and(json_encode($entries))->not->toContain('Jane Doe');
});

it('ignores a login of something that is not an admin', function () {
    $other = new class extends Illuminate\Foundation\Auth\User {};

    event(new Login('web', $other, false));

    expect(AuditEntry::query()->count())->toBe(0);
});

it('keeps the chain intact across authentication events', function () {
    $user = User::factory()->create();

    event(new Login('web', $user, false));
    TwoFactorAuthenticationConfirmed::dispatch($user);

    expect(app(AuditChainVerifier::class)->verify())->toBeNull();
});
