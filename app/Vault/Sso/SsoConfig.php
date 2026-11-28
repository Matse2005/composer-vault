<?php

namespace App\Vault\Sso;

use App\Models\Role;
use App\Models\User;
use App\Vault\Settings\VaultSettings;

/**
 * Typed access to the single sign-on settings. A value comes from the environment when one is set, then from the
 * dashboard (the vault_settings table), then from the default. It reads on every call, so a change applies at once.
 */
class SsoConfig
{
    public function __construct(private VaultSettings $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('sso.enabled');
    }

    public function issuer(): string
    {
        return trim((string) $this->settings->get('sso.issuer'));
    }

    public function clientId(): string
    {
        return trim((string) $this->settings->get('sso.client_id'));
    }

    public function clientSecret(): string
    {
        return (string) $this->settings->secret('sso.client_secret');
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        return $this->list((string) $this->settings->get('sso.scopes'), '/\s+/');
    }

    public function buttonLabel(): string
    {
        return (string) $this->settings->get('sso.button_label');
    }

    public function groupsClaim(): string
    {
        return (string) $this->settings->get('sso.groups_claim');
    }

    public function autoProvision(): bool
    {
        return (bool) $this->settings->get('sso.auto_provision');
    }

    public function enforced(): bool
    {
        return $this->enabled() && (bool) $this->settings->get('sso.enforce');
    }

    public function trustIdpMfa(): bool
    {
        return (bool) $this->settings->get('sso.trust_idp_mfa');
    }

    /**
     * Determine whether a person may sign in with a local credential (password or passkey). While single sign-on is
     * enforced, only break-glass accounts may.
     */
    public function allowsLocalLogin(User $user): bool
    {
        return ! $this->enforced() || $user->is_break_glass;
    }

    /**
     * Determine whether the minimum settings are present.
     */
    public function isComplete(): bool
    {
        return $this->issuer() !== '' && $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    /**
     * Determine whether sign-in through the provider is on and can work.
     */
    public function isActive(): bool
    {
        return $this->enabled() && $this->isComplete();
    }

    /**
     * The group names that give each role, in the order they are tried: Owner first, then the other roles with the
     * most permissions first. A role gets its groups from the Roles page. For Owner, Admin and Auditor an environment
     * variable (`VAULT_SSO_OWNER_GROUPS` and so on) overrides the page.
     *
     * @return list<array{role: Role, groups: list<string>}>
     */
    public function roleGroups(): array
    {
        $rows = [];

        foreach (Role::query()->get()->sortByDesc(fn (Role $role): int => $role->isOwner() ? PHP_INT_MAX : count($role->permissions)) as $role) {
            $override = $role->is_system ? config('vault.sso.role_groups.'.$role->slug) : null;
            $groups = filled($override) ? $this->list((string) $override) : $this->list(implode(',', (array) $role->sso_groups));

            $rows[] = ['role' => $role, 'groups' => $groups];
        }

        return $rows;
    }

    /**
     * Determine whether any group is mapped to a role.
     */
    public function hasRoleMapping(): bool
    {
        return array_filter($this->roleGroups(), fn (array $row): bool => $row['groups'] !== []) !== [];
    }

    /**
     * @return list<string> lowercased domains, empty for no restriction
     */
    public function allowedEmailDomains(): array
    {
        return array_map('mb_strtolower', $this->list((string) $this->settings->get('sso.allowed_email_domains')));
    }

    /**
     * @return list<string> acr values that count as multi-factor
     */
    public function mfaAcrValues(): array
    {
        return $this->list((string) $this->settings->get('sso.mfa_acr_values'));
    }

    /**
     * @return list<string>
     */
    private function list(string $value, string $separator = '/,/'): array
    {
        return array_values(array_filter(array_map('trim', preg_split($separator, $value) ?: []), fn (string $item): bool => $item !== ''));
    }
}
