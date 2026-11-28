<?php

use App\Actions\Vault\AcceptInvitation;
use App\Actions\Vault\InviteAdmin;
use App\Actions\Vault\RevokeInvitation;
use App\Enums\AdminRole;
use App\Exceptions\Vault\InvalidInvitationException;
use App\Exceptions\Vault\InvitationNotAllowedException;
use App\Models\AuditEntry;
use App\Models\Invitation;
use App\Models\User;
use App\Vault\Invitations\AdminInvitation;
use App\Vault\Invitations\IssuedInvitation;
use App\Vault\Settings\VaultSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

function invite(string $email = 'new.admin@example.com', ?User $inviter = null): IssuedInvitation
{
    $temporary = $inviter === null ? User::factory()->create() : null;
    $issued = app(InviteAdmin::class)->handle($email, $inviter ?? $temporary);
    $temporary?->delete();

    return $issued;
}

function plainTokenOf(IssuedInvitation $issued): string
{
    return basename(parse_url($issued->url, PHP_URL_PATH));
}

it('issues an invitation with a hashed 48 character token and an expiry', function () {
    $this->freezeTime();
    $admin = User::factory()->create();

    $issued = invite('New.Admin@Example.COM ', $admin);
    $plain = plainTokenOf($issued);
    $invitation = $issued->invitation->fresh();

    expect($plain)->toMatch('/^[A-Za-z0-9]{48}$/')
        ->and($issued->url)->toBe(route('invitations.accept', $plain))
        ->and($invitation->email)->toBe('new.admin@example.com')
        ->and($invitation->token_hash)->toBe(hash('sha256', $plain))
        ->and($invitation->getKey())->toHaveLength(26)
        ->and($invitation->invited_by)->toBe($admin->id)
        ->and($invitation->expires_at->toDateTimeString())->toBe(now()->addHours(48)->toDateTimeString())
        ->and($invitation->accepted_at)->toBeNull()
        ->and($invitation->revoked_at)->toBeNull()
        ->and($invitation->isUsable())->toBeTrue();
});

it('uses the configured expiry', function () {
    $this->freezeTime();
    config(['vault.invitations.expire_hours' => 2]);

    expect(invite()->invitation->expires_at->toDateTimeString())->toBe(now()->addHours(2)->toDateTimeString());
});

it('defaults to an expiry of 48 hours', function () {
    expect(app(VaultSettings::class)->invitationExpireHours())->toBe(48);
});

it('can be issued without an inviter, for the installer', function () {
    $issued = app(InviteAdmin::class)->handleFirstOwner('first@example.test');

    expect($issued->invitation->invited_by)->toBeNull()->and($issued->invitation->role)->toBe(AdminRole::Owner);
});

it('does not store the plain token in any column', function () {
    $issued = invite();
    $plain = plainTokenOf($issued);

    expect(json_encode(DB::table('invitations')->get()))->not->toContain($plain)
        ->and(json_encode(DB::table('activity_log')->get()))->not->toContain($plain);
});

it('hides the token hash and the url from dumps and json', function () {
    $issued = invite();
    $plain = plainTokenOf($issued);

    ob_start();
    var_dump($issued);
    $dump = ob_get_clean();

    expect($dump)->not->toContain($plain)
        ->and(print_r($issued, true))->not->toContain($plain)
        ->and($issued->invitation->toJson())->not->toContain($issued->invitation->token_hash);
});

it('audits the invitation without the email address', function () {
    $admin = User::factory()->create();
    $this->actingAs($admin);

    $issued = invite('someone.private@example.com', $admin);

    $entry = AuditEntry::query()->sole();

    expect($entry->event)->toBe('admin_invited')
        ->and($entry->subject_type)->toBe(Invitation::class)
        ->and($entry->subject_id)->toBe($issued->invitation->id)
        ->and((string) $entry->causer_id)->toBe((string) $admin->id)
        ->and(json_encode(DB::table('activity_log')->get()))->not->toContain('someone.private')
        ->not->toContain('example.com');
});

it('sends the invitation mail with the url when a real mailer is configured', function () {
    Notification::fake();
    config(['mail.default' => 'smtp']);

    $issued = invite('new.admin@example.com');

    Notification::assertSentOnDemand(AdminInvitation::class, function (AdminInvitation $notification, array $channels, object $notifiable) use ($issued): bool {
        $mail = $notification->toMail($notifiable);

        return $notifiable->routes['mail'] === 'new.admin@example.com'
            && $mail->actionUrl === $issued->url
            && $channels === ['mail'];
    });
});

it('does not mail the url when the mailer only logs or collects messages, but still returns it', function (string $mailer) {
    Notification::fake();
    config(['mail.default' => $mailer]);

    $issued = invite();

    Notification::assertNothingSent();
    expect($issued->url)->toStartWith('http');
})->with(['log', 'array']);

it('refuses an email address that already belongs to an admin, in any letter case', function () {
    User::factory()->create(['email' => 'Existing.Admin@Example.com']);

    invite('existing.admin@example.com');
})->throws(InvitationNotAllowedException::class);

it('refuses an email address that already has a usable invitation', function () {
    invite('new.admin@example.com');

    invite('NEW.admin@example.com');
})->throws(InvitationNotAllowedException::class);

it('allows a new invitation after the previous one expired, was revoked or was accepted elsewhere', function () {
    $first = invite('new.admin@example.com');
    $first->invitation->forceFill(['expires_at' => now()->subMinute()])->save();
    $second = invite('new.admin@example.com');
    asOwner(fn () => app(RevokeInvitation::class)->handle($second->invitation));

    expect(invite('new.admin@example.com')->invitation->isUsable())->toBeTrue()
        ->and(Invitation::query()->count())->toBe(3);
});

it('knows when an invitation is usable', function () {
    expect(Invitation::factory()->create()->isUsable())->toBeTrue()
        ->and(Invitation::factory()->expired()->create()->isUsable())->toBeFalse()
        ->and(Invitation::factory()->accepted()->create()->isUsable())->toBeFalse()
        ->and(Invitation::factory()->revoked()->create()->isUsable())->toBeFalse();
});

it('revokes an invitation once, keeps the first time and audits it exactly once', function () {
    $issued = invite();

    asOwner(fn () => app(RevokeInvitation::class)->handle($issued->invitation));
    $revokedAt = $issued->invitation->fresh()->revoked_at;
    $this->travel(1)->hours();
    asOwner(fn () => app(RevokeInvitation::class)->handle($issued->invitation->fresh()));

    $revocations = AuditEntry::query()->where('event', 'invitation_revoked')->get();

    expect($issued->invitation->fresh()->isUsable())->toBeFalse()
        ->and($issued->invitation->fresh()->revoked_at->toDateTimeString())->toBe($revokedAt->toDateTimeString())
        ->and($revocations)->toHaveCount(1)
        ->and($revocations[0]->subject_id)->toBe($issued->invitation->id);
});

it('does not revoke an accepted invitation', function () {
    $invitation = Invitation::factory()->accepted()->create();

    asOwner(fn () => app(RevokeInvitation::class)->handle($invitation));

    expect($invitation->fresh()->revoked_at)->toBeNull();
});

it('creates a verified admin from a valid invitation', function () {
    $issued = invite('new.admin@example.com');

    $user = app(AcceptInvitation::class)->handle(plainTokenOf($issued), 'New Admin', 'a-Strong-pass-123');

    expect($user->exists)->toBeTrue()
        ->and($user->email)->toBe('new.admin@example.com')
        ->and($user->name)->toBe('New Admin')
        ->and(Hash::check('a-Strong-pass-123', $user->password))->toBeTrue()
        ->and($user->password)->not->toBe('a-Strong-pass-123')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->hasSecondFactor())->toBeFalse()
        ->and($issued->invitation->fresh()->accepted_at)->not->toBeNull()
        ->and($issued->invitation->fresh()->isUsable())->toBeFalse();
});

it('audits an admin joining with the new admin as actor and no personal data', function () {
    $issued = invite('new.admin@example.com');

    $user = app(AcceptInvitation::class)->handle(plainTokenOf($issued), 'Jane Doe', 'a-Strong-pass-123');

    $entry = AuditEntry::query()->where('event', 'admin_joined')->sole();

    expect($entry->subject_id)->toBe((string) $user->id)
        ->and((string) $entry->causer_id)->toBe((string) $user->id)
        ->and(json_encode(DB::table('activity_log')->get()))->not->toContain('Jane Doe')
        ->not->toContain('new.admin');
});

it('gives the same exception and message for every invalid state', function (Closure $makeToken) {
    $plain = $makeToken();

    $thrown = null;

    try {
        app(AcceptInvitation::class)->handle($plain, 'Name', 'a-Strong-pass-123');
    } catch (InvalidInvitationException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(InvalidInvitationException::class)
        ->and($thrown->getMessage())->toBe('This invitation is invalid or has expired.')
        ->and(User::query()->count())->toBe(0);
})->with([
    'unknown' => [fn () => str_repeat('a', 48)],
    'malformed' => [fn () => 'short'],
    'empty' => [fn () => ''],
    'expired' => [function () {
        $issued = invite();
        $issued->invitation->forceFill(['expires_at' => now()->subMinute()])->save();

        return plainTokenOf($issued);
    }],
    'revoked' => [function () {
        $issued = invite();
        asOwner(fn () => app(RevokeInvitation::class)->handle($issued->invitation));

        return plainTokenOf($issued);
    }],
    'accepted' => [function () {
        $issued = invite();
        $issued->invitation->forceFill(['accepted_at' => now()])->save();

        return plainTokenOf($issued);
    }],
]);

it('cannot be used twice', function () {
    $plain = plainTokenOf(invite());

    app(AcceptInvitation::class)->handle($plain, 'First', 'a-Strong-pass-123');

    expect(fn () => app(AcceptInvitation::class)->handle($plain, 'Second', 'another-Strong-pass-123'))
        ->toThrow(InvalidInvitationException::class)
        ->and(User::query()->count())->toBe(1);
});

it('cannot be used twice when both attempts hold the same stale invitation', function () {
    $issued = invite();
    $plain = plainTokenOf($issued);
    $staleCopy = Invitation::query()->find($issued->invitation->id);

    app(AcceptInvitation::class)->handle($plain, 'First', 'a-Strong-pass-123');

    expect($staleCopy->isUsable())->toBeTrue()
        ->and(fn () => app(AcceptInvitation::class)->handle($plain, 'Second', 'another-Strong-pass-123'))
        ->toThrow(InvalidInvitationException::class)
        ->and(User::query()->count())->toBe(1);
});

it('refuses an invitation whose email address was registered in the meantime', function () {
    $issued = invite('new.admin@example.com');
    User::factory()->create(['email' => 'new.admin@example.com']);

    expect(fn () => app(AcceptInvitation::class)->handle(plainTokenOf($issued), 'Name', 'a-Strong-pass-123'))
        ->toThrow(InvalidInvitationException::class)
        ->and(User::query()->count())->toBe(1);
});

it('leaves no audit entry behind when accepting fails', function () {
    try {
        app(AcceptInvitation::class)->handle(str_repeat('a', 48), 'Name', 'a-Strong-pass-123');
    } catch (InvalidInvitationException) {
    }

    expect(AuditEntry::query()->count())->toBe(0);
});
