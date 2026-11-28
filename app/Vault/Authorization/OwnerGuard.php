<?php

namespace App\Vault\Authorization;

use App\Enums\AdminRole;
use App\Exceptions\Vault\LastOwnerException;
use App\Models\User;

/**
 * Keeps at least one active Owner in the vault, so the company can always reach the owner-only pages.
 */
final class OwnerGuard
{
    /**
     * Refuse when the admin is an active Owner and no other active Owner exists. Call it inside the transaction that
     * demotes or deactivates the admin; the owner rows are locked so two changes cannot remove the last two Owners.
     *
     * @throws LastOwnerException
     */
    public static function assertAnotherActiveOwnerExists(User $admin): void
    {
        if ($admin->role !== AdminRole::Owner || $admin->isDeactivated()) {
            return;
        }

        $another = User::query()
            ->active()
            ->withRole(AdminRole::Owner)
            ->whereKeyNot($admin->getKey())
            ->lockForUpdate()
            ->exists();

        if (! $another) {
            throw LastOwnerException::create();
        }
    }
}
