<?php

namespace App\Vault\Sso;

use App\Models\Role;

/**
 * Turns the groups of the ID token into a role through the mapping on the Roles page. The first role whose groups
 * match wins, and the roles are tried from the most powerful down.
 */
class RoleResolver
{
    public function __construct(private SsoConfig $config) {}

    /**
     * Get the role the groups give, or null when no group matches or no mapping is configured.
     *
     * @param  array<string, mixed>  $claims
     */
    public function resolve(array $claims): ?Role
    {
        $groups = $this->groupsOf($claims);

        foreach ($this->config->roleGroups() as $row) {
            if (array_intersect($row['groups'], $groups) !== []) {
                return $row['role'];
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
