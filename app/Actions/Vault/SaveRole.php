<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Exceptions\Vault\RoleEscalationException;
use App\Models\Role;
use App\Models\User;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use App\Vault\Authorization\RoleGuard;
use App\Vault\Authorization\RolePermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class SaveRole
{
    private const RESERVED_SLUGS = ['owner', 'admin', 'auditor', 'custom'];

    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Create a role or change one. The Owner role cannot be changed. Nobody grants a permission they do not hold, and
     * nobody changes the permissions of their own role. The audit entry lists the permissions added and removed.
     *
     * @param  list<string>  $permissions
     * @param  list<string>  $ssoGroups  identity provider groups that give this role
     *
     * @throws RoleEscalationException
     */
    public function handle(?Role $role, string $name, array $permissions, array $ssoGroups, User $actor): Role
    {
        Gate::forUser($actor)->authorize(Ability::RolesManage);

        $unknown = array_diff($permissions, Ability::all());

        if ($unknown !== []) {
            throw RoleEscalationException::invalid(__('Unknown permission: :permission.', ['permission' => (string) array_values($unknown)[0]]));
        }

        $permissions = array_values(array_unique($permissions));
        $groups = array_values(array_unique(array_filter(array_map('trim', $ssoGroups), fn (string $group): bool => $group !== '')));

        return DB::transaction(function () use ($role, $name, $permissions, $groups, $actor): Role {
            $creating = $role === null;
            $role = $creating ? (new Role)->forceFill(['is_system' => false]) : Role::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();

            if ($role->isOwner()) {
                throw RoleEscalationException::systemRole();
            }

            $old = $creating ? [] : $role->permissions;
            $added = array_values(array_diff($permissions, $old));
            $removed = array_values(array_diff($old, $permissions));

            if ($added !== [] || $removed !== []) {
                if (! $creating && RoleGuard::isRoleOf($actor, $role)) {
                    throw RoleEscalationException::ownRole();
                }

                $missing = array_values(array_diff($added, app(RolePermissions::class)->of($actor)));

                if ($missing !== []) {
                    throw RoleEscalationException::cannotGrant($missing);
                }
            }

            if ($creating) {
                $role->name = $name;
                $role->slug = $this->uniqueSlug($name);
            } elseif (! $role->is_system) {
                $role->name = $name;
            }

            $oldGroups = (array) $role->sso_groups;
            $role->permissions = $permissions;
            $role->sso_groups = $groups === [] ? null : $groups;
            $role->save();

            app(RolePermissions::class)->forget();

            $this->auditLogger->record(
                $creating ? AuditAction::RoleCreated : AuditAction::RoleChanged,
                $role,
                $creating ? ['name' => $role->name, 'permissions' => $permissions] : ['added' => $added, 'removed' => $removed, 'groups_changed' => $groups !== $oldGroups],
                $actor,
            );

            return $role;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'role';
        $slug = $base;
        $counter = 2;

        while (in_array($slug, self::RESERVED_SLUGS, true) || Role::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
    }
}
