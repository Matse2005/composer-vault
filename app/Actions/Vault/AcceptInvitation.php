<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Exceptions\Vault\InvalidInvitationException;
use App\Models\Invitation;
use App\Models\User;
use App\Vault\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

class AcceptInvitation
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Turn a usable invitation into an admin. The invitation row is locked, so two attempts with the same token
     * cannot both succeed. The admin gets a verified address and no second factor yet: the second factor
     * middleware takes it from there.
     *
     * @throws InvalidInvitationException For every reason the invitation cannot be accepted, with one message.
     */
    public function handle(#[SensitiveParameter] string $plainToken, string $name, #[SensitiveParameter] string $password): User
    {
        return DB::transaction(function () use ($plainToken, $name, $password): User {
            $invitation = Invitation::query()
                ->where('token_hash', hash('sha256', $plainToken))
                ->lockForUpdate()
                ->first();

            if ($invitation === null || ! $invitation->isUsable() || $this->emailIsTaken($invitation->email)) {
                throw new InvalidInvitationException;
            }

            $user = (new User)->forceFill([
                'name' => $name,
                'email' => $invitation->email,
                'password' => $password,
                'email_verified_at' => now(),
            ]);
            $user->save();

            $invitation->forceFill(['accepted_at' => now()])->save();

            $this->auditLogger->record(AuditAction::AdminJoined, $user, ['invitation_id' => $invitation->id], $user);

            return $user;
        });
    }

    private function emailIsTaken(string $email): bool
    {
        return User::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->exists();
    }
}
