<?php

namespace App\Actions\Vault;

use App\Enums\AdminRole;
use App\Enums\AuditAction;
use App\Exceptions\Vault\LastOwnerException;
use App\Exceptions\Vault\RoleEscalationException;
use App\Models\Role;
use App\Models\User;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use App\Vault\Authorization\OwnerGuard;
use App\Vault\Authorization\RoleGuard;
use App\Vault\Compliance\SecurityAlerts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ChangeAdminRole
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Change the role of an admin. Nothing happens, and nothing is audited, when the role stays the same.
     *
     * Nobody changes their own role. Only an Owner gives or takes away the Owner role. Nobody gives a role with a
     * permission they do not hold themselves.
     *
     * @throws RoleEscalationException
     * @throws LastOwnerException when the admin is the last active Owner and the new role is not Owner
     */
    public function handle(User $admin, AdminRole|Role $role, User $actor): void
    {
        Gate::forUser($actor)->authorize(Ability::AdminsManage);

        $role = $role instanceof Role ? $role : Role::forAdminRole($role);

        if ($admin->is($actor)) {
            throw RoleEscalationException::ownRole();
        }

        DB::transaction(function () use ($admin, $role, $actor): void {
            $admin->refresh();

            if (RoleGuard::isRoleOf($admin, $role)) {
                return;
            }

            if ($admin->role === AdminRole::Owner && $actor->role !== AdminRole::Owner) {
                throw RoleEscalationException::ownerOnly();
            }

            RoleGuard::assertMayAssign($actor, $role);

            if (! $role->isOwner()) {
                OwnerGuard::assertAnotherActiveOwnerExists($admin);
            }

            $old = $admin->customRole->slug ?? $admin->role->value;
            $keepsBreakGlass = $role->isOwner() && $admin->is_break_glass;

            $role->assignTo($admin);
            $admin->forceFill(['is_break_glass' => $keepsBreakGlass])->save();

            $this->auditLogger->record(AuditAction::AdminRoleChanged, $admin, ['old_role' => $old, 'new_role' => $role->slug], $actor);

            if ($role->isOwner()) {
                DB::afterCommit(fn () => app(SecurityAlerts::class)->ownerChange($admin, 'Admin became an Owner', $actor));
            }
        });
    }
}
