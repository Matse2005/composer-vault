<?php

namespace App\Actions\Vault;

use App\Enums\AuditAction;
use App\Exceptions\Vault\RoleEscalationException;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\User;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteRole
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * Delete a role. A system role cannot be deleted, and neither can a role that an admin or an invitation uses:
     * reassign them first.
     *
     * @throws RoleEscalationException
     */
    public function handle(Role $role, User $actor): void
    {
        Gate::forUser($actor)->authorize(Ability::RolesManage);

        DB::transaction(function () use ($role, $actor): void {
            $role = Role::query()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();

            if ($role->is_system) {
                throw RoleEscalationException::systemRole();
            }

            if (User::query()->where('custom_role_id', $role->id)->exists() || Invitation::query()->where('custom_role_id', $role->id)->exists()) {
                throw RoleEscalationException::inUse();
            }

            $name = $role->name;
            $role->delete();

            $this->auditLogger->record(AuditAction::RoleDeleted, null, ['name' => $name], $actor, $name);
        });
    }
}
