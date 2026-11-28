<?php

namespace App\Enums;

enum AuditAction: string
{
    case ProjectCreated = 'project_created';
    case ProjectUpdated = 'project_updated';
    case ProjectDeleted = 'project_deleted';
    case CredentialCreated = 'credential_created';
    case CredentialUpdated = 'credential_updated';
    case CredentialSecretChanged = 'credential_secret_changed';
    case CredentialDeleted = 'credential_deleted';
    case CredentialLinksChanged = 'credential_links_changed';
    case TokenIssued = 'token_issued';
    case TokenUpdated = 'token_updated';
    case TokenRegenerated = 'token_regenerated';
    case TokenRevoked = 'token_revoked';
    case TokenDeleted = 'token_deleted';
    case AdminInvited = 'admin_invited';
    case InvitationRevoked = 'invitation_revoked';
    case AdminJoined = 'admin_joined';
    case AdminRemoved = 'admin_removed';
    case AdminDeactivated = 'admin_deactivated';
    case AdminLoggedIn = 'admin_logged_in';
    case TwoFactorEnabled = 'two_factor_enabled';
    case TwoFactorDisabled = 'two_factor_disabled';
    case PasskeyAdded = 'passkey_added';
    case PasskeyRemoved = 'passkey_removed';
    case KeyRotated = 'key_rotated';
    case VaultInstalled = 'vault_installed';
    case SettingsChanged = 'settings_changed';
    case AdminRoleChanged = 'admin_role_changed';
    case SsoLoginRejected = 'sso_login_rejected';
    case SsoAccountLinked = 'sso_account_linked';
    case AdminProvisioned = 'admin_provisioned';
    case BreakGlassChanged = 'break_glass_changed';
    case BreakGlassLogin = 'break_glass_login';

    /**
     * Get the short English sentence stored as the description of the entry, for example "Credential created".
     */
    public function description(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
