<?php

namespace App\Vault\Authorization;

use App\Enums\AdminRole;

/**
 * The fixed list of permissions. A role is a set of them, and every action and page checks one. The Owner role holds
 * every permission and cannot be edited. Nothing is granted unless a role lists it.
 */
final class Ability
{
    public const string ProjectsView = 'projects.view';

    public const string ProjectsManage = 'projects.manage';

    public const string CredentialsView = 'credentials.view';

    public const string CredentialsManage = 'credentials.manage';

    public const string TokensView = 'tokens.view';

    public const string TokensManage = 'tokens.manage';

    public const string LogsView = 'logs.view';

    public const string LogsExport = 'logs.export';

    public const string AdminsView = 'admins.view';

    public const string AdminsManage = 'admins.manage';

    public const string RolesManage = 'roles.manage';

    public const string SettingsOrganisation = 'settings.organisation';

    public const string SettingsSecurity = 'settings.security';

    public const string SettingsApi = 'settings.api';

    public const string SettingsPolicies = 'settings.policies';

    public const string SettingsRetention = 'settings.retention';

    public const string SettingsSso = 'settings.sso';

    public const string SettingsNotifications = 'settings.notifications';

    public const string ComplianceView = 'compliance.view';

    public const string ComplianceManage = 'compliance.manage';

    public const string IncidentsManage = 'incidents.manage';

    public const string HealthRun = 'health.run';

    /** Held by the Owner only: turning change approval on or off. Not part of any editable role. */
    public const string ApprovalManage = 'approval.manage';

    /**
     * Every permission that a role can hold, grouped by area with one line each, in the order of the role page.
     *
     * @return array<string, array<string, string>>
     */
    public static function groups(): array
    {
        return [
            'Projects' => [
                self::ProjectsView => 'See the list of projects.',
                self::ProjectsManage => 'Create, edit and delete projects.',
            ],
            'Credentials' => [
                self::CredentialsView => 'See the credentials of a project, never their secrets.',
                self::CredentialsManage => 'Add, edit, move and delete credentials, and change their secrets.',
            ],
            'Tokens' => [
                self::TokensView => 'See the server tokens of a project.',
                self::TokensManage => 'Issue, change, regenerate, revoke and delete server tokens.',
            ],
            'Logs' => [
                self::LogsView => 'Read the access log, the audit log and the security status.',
                self::LogsExport => 'Export logs.',
            ],
            'Admins' => [
                self::AdminsView => 'See the admins and their invitations.',
                self::AdminsManage => 'Invite, deactivate and reactivate admins, change their role, mark break-glass accounts.',
                self::RolesManage => 'Create, edit and delete roles and map identity provider groups to them.',
            ],
            'Settings' => [
                self::SettingsOrganisation => 'Edit the organisation details.',
                self::SettingsSecurity => 'Edit the admin IP allowlist, session limits and the security log channel.',
                self::SettingsApi => 'Edit the API rate limits.',
                self::SettingsPolicies => 'Edit the token, rotation and review policies.',
                self::SettingsRetention => 'Edit how long logs are kept.',
                self::SettingsSso => 'Edit single sign-on.',
                self::SettingsNotifications => 'Edit the mail transport, alert recipients and alert rules.',
            ],
            'Compliance' => [
                self::ComplianceView => 'Read the compliance report, the access review and the data processing page.',
                self::ComplianceManage => 'Complete access reviews, export personal data and pseudonymise admins.',
                self::IncidentsManage => 'Use the emergency actions and keep incident records.',
            ],
            'Operations' => [
                self::HealthRun => 'Run the health checks now.',
            ],
        ];
    }

    /**
     * Every permission that a role can hold.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(array_merge(...array_values(self::groups())));
    }

    /**
     * The permissions of the Admin and Auditor roles as they are seeded, which match the phase 8 matrix.
     *
     * @return list<string>
     */
    public static function defaultsFor(AdminRole $role): array
    {
        return match ($role) {
            AdminRole::Owner => self::all(),
            AdminRole::Admin => [
                self::ProjectsView, self::ProjectsManage, self::CredentialsView, self::CredentialsManage, self::TokensView, self::TokensManage,
                self::LogsView, self::ComplianceView, self::HealthRun,
            ],
            AdminRole::Auditor => [
                self::ProjectsView, self::CredentialsView, self::TokensView, self::LogsView, self::LogsExport, self::ComplianceView, self::HealthRun,
            ],
            AdminRole::Custom => [],
        };
    }
}
