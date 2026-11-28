<?php

namespace App\Enums;

enum AdminRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Auditor = 'auditor';

    /** A role made on the Roles page. Which one is in `users.custom_role_id`. */
    case Custom = 'custom';

    /**
     * Get the name of the role as shown in the interface.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Get one line about what the role may do.
     */
    public function summary(): string
    {
        return match ($this) {
            self::Owner => 'Full control, including admins, settings and compliance tools.',
            self::Admin => 'Manages projects, credentials and tokens, and reads the logs.',
            self::Auditor => 'Reads everything and exports logs, but changes nothing.',
            self::Custom => 'A role made on the Roles page.',
        };
    }
}
