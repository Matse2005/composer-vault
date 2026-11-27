<?php

namespace App\Vault\Tokens;

use App\Exceptions\Vault\InvalidAdminIpAllowlistException;

class AdminIpList
{
    /**
     * Parse the admin IP allowlist, given as a comma separated string (VAULT_ADMIN_ALLOWED_IPS) or as an array.
     * An empty list means that every address may reach the admin interface.
     *
     * @param  string|array<int, string>|null  $configured
     * @return array<int, string>
     *
     * @throws InvalidAdminIpAllowlistException When an entry is not an IP address or CIDR range.
     */
    public static function parse(string|array|null $configured): array
    {
        $items = is_array($configured) ? $configured : explode(',', (string) $configured);

        $entries = array_values(array_filter(
            array_map(fn (mixed $entry): string => trim((string) $entry), $items),
            fn (string $entry): bool => $entry !== '',
        ));

        foreach ($entries as $entry) {
            if (! IpAllowlist::isValidEntry($entry)) {
                throw new InvalidAdminIpAllowlistException("VAULT_ADMIN_ALLOWED_IPS contains an entry that is not an IP address or CIDR range: [{$entry}].");
            }
        }

        return $entries;
    }
}
