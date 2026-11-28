<?php

namespace App\Vault\Authorization;

use App\Enums\AdminRole;

/**
 * The capabilities of the admin roles, one gate each. A role holds a capability when it is listed here: nothing is
 * granted by default.
 */
final class Ability
{
    /** View projects, credentials and tokens (metadata only, never a secret). */
    public const string ViewVault = 'vault.view';

    /** Create, edit and delete projects, credentials and tokens, and link them. */
    public const string ManageVault = 'vault.manage';

    /** View the access log, the audit log, the security status and the compliance report. */
    public const string ViewLogs = 'logs.view';

    /** Export logs. */
    public const string ExportLogs = 'logs.export';

    /** Invite, deactivate and change the role of admins. */
    public const string ManageAdmins = 'admins.manage';

    /** Organisation details, retention and policies. */
    public const string ManageSettings = 'settings.manage';

    /** Incident actions, access reviews and the personal data tools. */
    public const string ManageCompliance = 'compliance.manage';

    /**
     * Get the roles that hold a capability.
     *
     * @return list<AdminRole>
     */
    public static function rolesFor(string $ability): array
    {
        return match ($ability) {
            self::ViewVault, self::ViewLogs => [AdminRole::Owner, AdminRole::Admin, AdminRole::Auditor],
            self::ManageVault => [AdminRole::Owner, AdminRole::Admin],
            self::ExportLogs => [AdminRole::Owner, AdminRole::Auditor],
            self::ManageAdmins, self::ManageSettings, self::ManageCompliance => [AdminRole::Owner],
            default => [],
        };
    }

    /**
     * Get every capability.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::ViewVault,
            self::ManageVault,
            self::ViewLogs,
            self::ExportLogs,
            self::ManageAdmins,
            self::ManageSettings,
            self::ManageCompliance,
        ];
    }
}
