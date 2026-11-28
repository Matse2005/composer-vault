<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Exceptions\Vault\CannotDeactivateAdminException;
use App\Exceptions\Vault\LastOwnerException;
use App\Models\Invitation;
use App\Models\User;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use App\Vault\Authorization\OwnerGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class DeactivateAdmin
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Deactivate an admin: the admin can no longer log in, open sessions end and the remember me token stops working.
     * Invitations the admin sent and that nobody accepted yet are revoked. The account is kept so that audit entries
     * stay readable. Nothing happens for an admin who is deactivated already.
     *
     * @throws CannotDeactivateAdminException when the admin is the actor
     * @throws LastOwnerException when the admin is the last active Owner
     */
    public function handle(User $admin, User $actor): void
    {
        Gate::forUser($actor)->authorize(Ability::ManageAdmins);

        if ($admin->is($actor)) {
            throw CannotDeactivateAdminException::yourself();
        }

        $this->deactivate($admin, $actor, []);
    }

    /**
     * Deactivate an admin for inactivity. Only the scheduled command calls this: there is no actor, and the entry says
     * the schedule did it.
     *
     * @throws LastOwnerException when the admin is the last active Owner
     */
    public function handleInactive(User $admin): void
    {
        $this->deactivate($admin, null, ['source' => 'schedule']);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function deactivate(User $admin, ?User $actor, array $changes): void
    {
        DB::transaction(function () use ($admin, $actor, $changes): void {
            $admin->refresh();

            if ($admin->isDeactivated()) {
                return;
            }

            OwnerGuard::assertAnotherActiveOwnerExists($admin);

            $admin->forceFill([
                'deactivated_at' => now(),
                'is_break_glass' => false,
                'remember_token' => Str::random(60),
            ])->save();

            $revoked = Invitation::query()
                ->where('invited_by', $admin->getKey())
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $admin->getKey())->delete();
            }

            $this->auditLogger->record(AuditAction::AdminDeactivated, $admin, [...$changes, 'revoked_invitations' => $revoked], $actor);
        });
    }
}
