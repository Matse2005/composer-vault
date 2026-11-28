<?php

namespace App\Actions\Vault;

use App\Enums\AdminRole;
use App\Enums\AuditAction;
use App\Exceptions\Vault\LastOwnerException;
use App\Models\User;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use App\Vault\Authorization\OwnerGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ChangeAdminRole
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Change the role of an admin. Nothing happens, and nothing is audited, when the role stays the same.
     *
     * @throws LastOwnerException when the admin is the last active Owner and the new role is not Owner
     */
    public function handle(User $admin, AdminRole $role, User $actor): void
    {
        Gate::forUser($actor)->authorize(Ability::ManageAdmins);

        DB::transaction(function () use ($admin, $role, $actor): void {
            $admin->refresh();

            if ($admin->role === $role) {
                return;
            }

            if ($role !== AdminRole::Owner) {
                OwnerGuard::assertAnotherActiveOwnerExists($admin);
            }

            $old = $admin->role;
            $admin->forceFill(['role' => $role])->save();

            $this->auditLogger->record(AuditAction::AdminRoleChanged, $admin, ['old_role' => $old, 'new_role' => $role], $actor);
        });
    }
}
