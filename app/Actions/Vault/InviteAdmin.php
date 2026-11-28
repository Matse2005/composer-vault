<?php

namespace App\Actions\Vault;

use App\Enums\AdminRole;
use App\Enums\AuditAction;
use App\Exceptions\Vault\InvitationNotAllowedException;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\User;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use App\Vault\Authorization\RoleGuard;
use App\Vault\Invitations\AdminInvitation;
use App\Vault\Invitations\IssuedInvitation;
use App\Vault\Settings\VaultSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
     * @throws AuthorizationException When the inviter may not invite admins.
     */
    public function handle(string $email, User $inviter, AdminRole|Role $role = AdminRole::Admin): IssuedInvitation
    {
        Gate::forUser($inviter)->authorize(Ability::AdminsManage);

        $role = $role instanceof Role ? $role : Role::forAdminRole($role);
        RoleGuard::assertMayAssign($inviter, $role);

        return $this->issue($email, $inviter, $role);
    }

    /**
     * Invite the first Owner of a new install. Only the install command calls this: it needs no inviter, and it
     * refuses to run as soon as an admin exists.
     *
     * @throws InvitationNotAllowedException When the address has a usable invitation already.
     * @throws AuthorizationException When an admin exists or the call does not come from the console.
     */
    public function handleFirstOwner(string $email): IssuedInvitation
    {
        if (! app()->runningInConsole() || User::query()->exists()) {
            throw new AuthorizationException;
        }

        return $this->issue($email, null, Role::forAdminRole(AdminRole::Owner));
    }

    /**
     * @throws InvitationNotAllowedException
     */
    private function issue(string $email, ?User $inviter, Role $role): IssuedInvitation
    {
        $email = mb_strtolower(trim($email));

        $this->assertCanInvite($email);

        $plainToken = $this->generateToken();
        $expireHours = app(VaultSettings::class)->invitationExpireHours();

        $invitation = DB::transaction(function () use ($email, $role, $plainToken, $expireHours, $inviter): Invitation {
            $invitation = (new Invitation)->forceFill([
                'email' => $email,
                'role' => $role->adminRole(),
                'custom_role_id' => $role->is_system ? null : $role->id,
                'token_hash' => hash('sha256', $plainToken),
                'invited_by' => $inviter?->getKey(),
                'expires_at' => now()->addHours($expireHours),
            ]);
            $invitation->save();

            $this->auditLogger->record(AuditAction::AdminInvited, $invitation, ['expires_at' => $invitation->expires_at, 'role' => $role->slug], $inviter);

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
