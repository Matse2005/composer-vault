<?php

namespace App\Enums;

enum AlertRule: string
{
    case FailedTokenBurst = 'failed_token_burst';
    case TokenFromNewIp = 'token_from_new_ip';
    case BreakGlassLogin = 'break_glass_login';
    case OwnerChange = 'owner_change';
    case SsoRejectionBurst = 'sso_rejection_burst';
    case KeyRotation = 'key_rotation';
    case AuditChainBreak = 'audit_chain_break';
    case SettingsChange = 'settings_change';

    /**
     * Get the name of the rule as shown in alerts and on the notifications page.
     */
    public function label(): string
    {
        return match ($this) {
            self::FailedTokenBurst => 'Failed token burst',
            self::TokenFromNewIp => 'Token used from a new address',
            self::BreakGlassLogin => 'Break-glass login',
            self::OwnerChange => 'Owner change',
            self::SsoRejectionBurst => 'Single sign-on rejection burst',
            self::KeyRotation => 'Key rotation',
            self::AuditChainBreak => 'Audit chain break',
            self::SettingsChange => 'Retention or policy change',
        };
    }

    /**
     * Get what makes the rule fire. The thresholds are fixed in code.
     */
    public function trigger(): string
    {
        return match ($this) {
            self::FailedTokenBurst => '20 or more failed API attempts from one address within 10 minutes.',
            self::TokenFromNewIp => 'A token without an address allowlist is used from an address it never used before.',
            self::BreakGlassLogin => 'A break-glass account logs in with a local credential. The other Owners are told too.',
            self::OwnerChange => 'Someone becomes an Owner, or an Owner is deactivated. The other Owners are told too.',
            self::SsoRejectionBurst => '10 or more rejected single sign-on attempts within 10 minutes.',
            self::KeyRotation => 'The vault key was rotated.',
            self::AuditChainBreak => 'The audit log chain does not verify.',
            self::SettingsChange => 'A retention, access policy or other policy setting changed.',
        };
    }
}
