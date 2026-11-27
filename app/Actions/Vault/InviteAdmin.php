<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Exceptions\Vault\InvitationNotAllowedException;
use App\Models\Invitation;
use App\Models\User;
use App\Vault\Audit\AuditLogger;
use App\Vault\Invitations\AdminInvitation;
use App\Vault\Invitations\IssuedInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class InviteAdmin
{
    private const TOKEN_LENGTH = 48;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Invite someone to become an admin. The link with the plain token is in the returned value and in the mail, and
     * is stored nowhere. The mail is only sent with a real mailer: the log and array mailers would write the link,
     * and so the token, to a log.
     *
     * @throws InvitationNotAllowedException When the address already belongs to an admin or has a usable invitation.
     */
    public function handle(string $email, ?User $inviter): IssuedInvitation
    {
        $email = mb_strtolower(trim($email));

        $this->assertCanInvite($email);

        $plainToken = $this->generateToken();
        $expireHours = (int) config('vault.invitations.expire_hours');

        $invitation = DB::transaction(function () use ($email, $plainToken, $expireHours, $inviter): Invitation {
            $invitation = (new Invitation)->forceFill([
                'email' => $email,
                'token_hash' => hash('sha256', $plainToken),
                'invited_by' => $inviter?->getKey(),
                'expires_at' => now()->addHours($expireHours),
            ]);
            $invitation->save();

            $this->auditLogger->record(AuditAction::AdminInvited, $invitation, ['expires_at' => $invitation->expires_at], $inviter);

            return $invitation;
        });

        $url = route('invitations.accept', $plainToken);

        if (! in_array(config('mail.default'), ['log', 'array'], true)) {
            Notification::route('mail', $email)->notify(new AdminInvitation($url, $expireHours));
        }

        return new IssuedInvitation($invitation, $url);
    }

    /**
     * @throws InvitationNotAllowedException
     */
    private function assertCanInvite(string $email): void
    {
        if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw new InvitationNotAllowedException('That email address already belongs to an admin.');
        }

        $hasUsableInvitation = Invitation::query()
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->exists();

        if ($hasUsableInvitation) {
            throw new InvitationNotAllowedException('That email address already has an open invitation.');
        }
    }

    /**
     * Generate 48 characters from [A-Za-z0-9], one random_int call per character, so the choice is unbiased.
     */
    private function generateToken(): string
    {
        $token = '';

        for ($position = 0; $position < self::TOKEN_LENGTH; $position++) {
            $token .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $token;
    }
}
