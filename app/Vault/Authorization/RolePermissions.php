<?php

namespace App\Vault\Authorization;

use App\Enums\AdminRole;
use App\Models\Role;
use App\Models\User;

/**
 * Works out what a user may do from their role. The permissions are read from the roles table once per request (the
 * service is scoped) and never kept longer, so a change to a role applies at once on every server. The Owner holds
 * every permission, whatever the table says.
 */
class RolePermissions
{
    /** @var array<string, list<string>> */
    private array $memo = [];

    /**
     * Get the permissions of a user.
     *
     * @return list<string>
     */
    public function of(User $user): array
    {
        if ($user->role === AdminRole::Owner) {
            return [...Ability::all(), Ability::ApprovalManage];
        }

        $key = $user->role === AdminRole::Custom ? 'id:'.$user->custom_role_id : 'slug:'.$user->role->value;

        return $this->memo[$key] ??= $this->load($user);
    }

    public function allows(User $user, string $permission): bool
    {
        return in_array($permission, $this->of($user), true);
    }

    /**
     * Forget what was read, after a role changed in this request.
     */
    public function forget(): void
    {
        $this->memo = [];
    }

    /**
     * @return list<string>
     */
    private function load(User $user): array
    {
        $role = $user->role === AdminRole::Custom
            ? Role::query()->find($user->custom_role_id)
            : Role::query()->where('slug', $user->role->value)->where('is_system', true)->first();

        return $role === null ? [] : array_values(array_intersect($role->permissions, Ability::all()));
    }
}
