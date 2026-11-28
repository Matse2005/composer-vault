<?php

namespace App\Vault\Audit;

use App\Enums\AlertRule;
use App\Enums\AuditAction;
use App\Http\Controllers\SsoController;
use App\Http\Middleware\EnforceAbsoluteSessionLifetime;
use App\Models\User;
use App\Vault\Compliance\SecurityAlerts;
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

    /**
     * Audit a login and remember its time. The method is "sso" when the sign-in came through the identity provider
     * and "local" for the password and passkey logins.
     */
    public function handleLogin(Login $event): void
    {
        if ($event->user instanceof User) {
            $event->user->forceFill(['last_login_at' => now()])->save();
        }

        if (request()->hasSession()) {
            request()->session()->put(EnforceAbsoluteSessionLifetime::SESSION_KEY, now()->getTimestamp());
        }

        $method = request()->hasSession() ? request()->session()->pull(SsoController::METHOD_KEY, 'local') : 'local';

        $this->record(AuditAction::AdminLoggedIn, $event->user, ['method' => $method]);

        if ($event->user instanceof User && $event->user->is_break_glass && $method === 'local') {
            $this->record(AuditAction::BreakGlassLogin, $event->user);

            $alerts = app(SecurityAlerts::class);
            $alerts->send(AlertRule::BreakGlassLogin, (string) $event->user->getKey(), "Break-glass admin #{$event->user->getKey()} logged in from {$this->ip()}.", $alerts->otherOwners($event->user));
        }
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

    private function ip(): string
    {
        return (string) request()->ip();
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
