<?php

namespace App\Vault\Audit;

use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

/**
 * Audits the authentication events of the admins. The admin is both the subject and the actor of these entries.
 * Two-factor authentication counts as enabled when it is confirmed, because Fortify only enforces it from then on.
 */
class AuditAuthenticationEvents
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function handleLogin(Login $event): void
    {
        $this->record(AuditAction::AdminLoggedIn, $event->user);
    }

    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->record(AuditAction::TwoFactorEnabled, $event->user);
    }

    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->record(AuditAction::TwoFactorDisabled, $event->user);
    }

    public function handlePasskeyRegistered(PasskeyRegistered $event): void
    {
        $this->record(AuditAction::PasskeyAdded, $event->user, ['passkey_id' => $event->passkey->getKey()]);
    }

    public function handlePasskeyDeleted(PasskeyDeleted $event): void
    {
        $this->record(AuditAction::PasskeyRemoved, $event->user, ['passkey_id' => $event->passkey->getKey()]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function record(AuditAction $action, mixed $user, array $changes = []): void
    {
        if (! $user instanceof User) {
            return;
        }

        $this->auditLogger->record($action, $user, $changes, $user);
    }
}
