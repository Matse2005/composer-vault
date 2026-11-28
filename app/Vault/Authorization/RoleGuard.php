<?php

namespace App\Vault\Authorization;

use App\Enums\AdminRole;
use App\Exceptions\Vault\RoleEscalationException;
use App\Models\Role;
use App\Models\User;

/**
 * The guards against escalation of privilege, used by every action that gives a role to someone.
 */
final class RoleGuard
{
    /**
     * Refuse when the actor may not give the role: only an Owner gives the Owner role, and nobody gives a role with a
     * permission they do not hold themselves.
     *
     * @throws RoleEscalationException
     */
    public static function assertMayAssign(User $actor, Role $role): void
    {
        if ($role->isOwner() && $actor->role !== AdminRole::Owner) {
            throw RoleEscalationException::ownerOnly();
        }

        $held = app(RolePermissions::class)->of($actor);
        $missing = array_values(array_diff($role->isOwner() ? Ability::all() : $role->permissions, $held));

        if ($missing !== []) {
            throw RoleEscalationException::cannotGrant($missing);
        }
    }

    /**
     * Determine whether a role is the role the user has now.
     */
    public static function isRoleOf(User $user, Role $role): bool
    {
        return $user->role === $role->adminRole() && $user->custom_role_id === ($role->is_system ? null : $role->id);
    }
}
