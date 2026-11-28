<?php

namespace App\Vault\Settings;

use InvalidArgumentException;

/**
 * The single list of settings that can be stored. A setting that is not in this list cannot be stored. New settings
 * are added here, never as a new environment variable, unless they belong in the "stays in the environment" table of
 * docs/settings.md.
 */
final class SettingsRegistry
{
    /** The permission of the settings that only an Owner may change. */
    public const OWNER_ONLY = 'approval.manage';

    /** @var array<string, string> */
    private const ENVIRONMENT_VARIABLES = [
        'api.rate_limit_per_minute' => 'VAULT_API_RATE_LIMIT',
        'api.failed_attempts_per_minute' => 'VAULT_API_FAILED_ATTEMPTS',
        'admin.allowed_ips' => 'VAULT_ADMIN_ALLOWED_IPS',
        'security.session_idle_minutes' => 'SESSION_LIFETIME',
        'security.session_absolute_hours' => 'VAULT_SESSION_ABSOLUTE_HOURS',
        'security.inactive_deactivate_days' => 'VAULT_ADMIN_INACTIVE_DAYS',
        'security.log_channel' => 'VAULT_SECURITY_LOG_CHANNEL',
        'invitations.expire_hours' => 'VAULT_INVITATION_EXPIRE_HOURS',
        'mail.host' => 'MAIL_HOST',
        'mail.port' => 'MAIL_PORT',
        'mail.encryption' => 'MAIL_ENCRYPTION',
        'mail.username' => 'MAIL_USERNAME',
        'mail.password' => 'MAIL_PASSWORD',
        'mail.from_address' => 'MAIL_FROM_ADDRESS',
        'mail.from_name' => 'MAIL_FROM_NAME',
        'notifications.mail' => 'VAULT_HEALTH_NOTIFY_MAIL',
        'notifications.slack_webhook' => 'VAULT_HEALTH_NOTIFY_SLACK_WEBHOOK',
        'sso.enabled' => 'VAULT_SSO_ENABLED',
        'sso.issuer' => 'VAULT_SSO_ISSUER',
        'sso.client_id' => 'VAULT_SSO_CLIENT_ID',
        'sso.client_secret' => 'VAULT_SSO_CLIENT_SECRET',
        'sso.scopes' => 'VAULT_SSO_SCOPES',
        'sso.button_label' => 'VAULT_SSO_BUTTON_LABEL',
        'sso.groups_claim' => 'VAULT_SSO_GROUPS_CLAIM',
        'sso.owner_groups' => 'VAULT_SSO_OWNER_GROUPS',
        'sso.admin_groups' => 'VAULT_SSO_ADMIN_GROUPS',
        'sso.auditor_groups' => 'VAULT_SSO_AUDITOR_GROUPS',
        'sso.allowed_email_domains' => 'VAULT_SSO_ALLOWED_DOMAINS',
        'sso.auto_provision' => 'VAULT_SSO_AUTO_PROVISION',
        'sso.enforce' => 'VAULT_SSO_ENFORCE',
        'sso.trust_idp_mfa' => 'VAULT_SSO_TRUST_IDP_MFA',
        'sso.mfa_acr_values' => 'VAULT_SSO_MFA_ACR_VALUES',
    ];

    /** @var array<string, SettingDefinition>|null */
    private static ?array $definitions = null;

    /**
     * @return array<string, SettingDefinition>
     */
    public static function all(): array
    {
        if (self::$definitions !== null) {
            return self::$definitions;
        }

        $definitions = [];
        $defaults = VaultSettings::defaults();

        foreach (VaultSettings::rules() as $key => $rules) {
            [$area, $permission] = self::placeOf($key);

            $definitions[$key] = new SettingDefinition(
                key: $key,
                area: $area,
                permission: $permission,
                isCritical: self::isCritical($key),
                isSecret: false,
                envVariable: self::ENVIRONMENT_VARIABLES[$key] ?? null,
                default: $defaults[$key] ?? null,
                rules: $rules,
            );
        }

        foreach (VaultSettings::SECRET_KEYS as $key) {
            [$area, $permission] = self::placeOf($key);

            $definitions[$key] = new SettingDefinition($key, $area, $permission, self::isCritical($key), true, self::ENVIRONMENT_VARIABLES[$key], null, []);
        }

        return self::$definitions = $definitions;
    }

    /**
     * @throws InvalidArgumentException when the setting is not in the registry
     */
    public static function get(string $key): SettingDefinition
    {
        return self::all()[$key] ?? throw new InvalidArgumentException("Setting [{$key}] is not in the registry.");
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /**
     * The settings that need a permission, grouped by that permission.
     *
     * @return array<string, list<SettingDefinition>>
     */
    public static function byPermission(): array
    {
        $grouped = [];

        foreach (self::all() as $definition) {
            $grouped[$definition->permission][] = $definition;
        }

        return $grouped;
    }

    /**
     * @return array{0: string, 1: string} the area and the permission that edits the setting
     */
    private static function placeOf(string $key): array
    {
        return match (true) {
            str_starts_with($key, 'retention.') => ['retention', 'settings.retention'],
            str_starts_with($key, 'policy.') => ['policies', 'settings.policies'],
            str_starts_with($key, 'organisation.') => ['organisation', 'settings.organisation'],
            str_starts_with($key, 'api.') => ['api', 'settings.api'],
            str_starts_with($key, 'sso.') => ['sso', 'settings.sso'],
            str_starts_with($key, 'mail.'), str_starts_with($key, 'notifications.'), str_starts_with($key, 'alerts.') => ['notifications', 'settings.notifications'],
            $key === 'approval.enabled' => ['security', self::OWNER_ONLY],
            default => ['security', 'settings.security'],
        };
    }

    /**
     * Critical settings can lock people out, delete evidence or open access. They are guarded, and approved by a
     * second Owner when change approval is on.
     */
    private static function isCritical(string $key): bool
    {
        return str_starts_with($key, 'sso.')
            || str_starts_with($key, 'retention.')
            || in_array($key, ['admin.allowed_ips', 'approval.enabled', 'security.break_glass_bypasses_ip_allowlist'], true);
    }
}
