<?php

namespace App\Vault\Sso;

use App\Enums\AdminRole;

/**
 * Turns the groups of the ID token into a role through the configured mapping. The highest role wins.
 */
class RoleResolver
{
    public function __construct(private SsoConfig $config) {}

    /**
     * Get the role the groups give, or null when no group matches or no mapping is configured.
     *
     * @param  array<string, mixed>  $claims
     */
    public function resolve(array $claims): ?AdminRole
    {
        $groups = $this->groupsOf($claims);

        foreach ($this->config->roleGroups() as $role => $names) {
            if (array_intersect($names, $groups) !== []) {
                return AdminRole::from($role);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return list<string>
     */
    private function groupsOf(array $claims): array
    {
        $value = $claims[$this->config->groupsClaim()] ?? [];

        if (is_string($value)) {
            return [$value];
        }

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
