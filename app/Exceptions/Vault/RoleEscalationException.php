<?php

namespace App\Exceptions\Vault;

use RuntimeException;

class RoleEscalationException extends RuntimeException
{
    /**
     * @param  list<string>  $permissions
     */
    public static function cannotGrant(array $permissions): self
    {
        return new self(__('You cannot grant a permission you do not have yourself: :permissions.', ['permissions' => implode(', ', $permissions)]));
    }

    public static function ownerOnly(): self
    {
        return new self(__('Only an Owner can assign or remove the Owner role.'));
    }

    public static function ownRole(): self
    {
        return new self(__('You cannot change your own role or the permissions of your own role.'));
    }

    public static function inUse(): self
    {
        return new self(__('This role is in use. Give its admins and invitations another role first.'));
    }

    public static function systemRole(): self
    {
        return new self(__('This is a system role and cannot be changed or deleted.'));
    }

    public static function invalid(string $message): self
    {
        return new self($message);
    }
}
