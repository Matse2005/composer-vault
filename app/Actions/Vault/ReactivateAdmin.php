<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Models\User;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ReactivateAdmin
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Let a deactivated admin log in again. The inactivity clock restarts (the last login is set to now), so the admin is not deactivated again
     * the next day. Nothing happens for an admin who is active already.
     */
    public function handle(User $admin, User $actor): void
    {
        Gate::forUser($actor)->authorize(Ability::ManageAdmins);

        DB::transaction(function () use ($admin, $actor): void {
            $admin->refresh();

            if (! $admin->isDeactivated() || $admin->pseudonymised_at !== null) {
                return;
            }

            $admin->forceFill(['deactivated_at' => null, 'last_login_at' => now()])->save();

            $this->auditLogger->record(AuditAction::AdminReactivated, $admin, [], $actor);
        });
    }
}
