<?php

namespace App\Vault\Tokens;

use InvalidArgumentException;
use Symfony\Component\HttpFoundation\IpUtils;

class IpAllowlist
{
    /**
     * Determine whether an entry is a single IPv4 or IPv6 address, or a CIDR range with a valid prefix length.
     */
    public static function isValidEntry(string $entry): bool
    {
        if ($entry === '' || $entry !== trim($entry)) {
            return false;
        }

        if (! str_contains($entry, '/')) {
            return filter_var($entry, FILTER_VALIDATE_IP) !== false;
        }

        [$address, $prefix] = explode('/', $entry, 2);

        if (preg_match('/^(0|[1-9][0-9]*)$/', $prefix) !== 1) {
            return false;
        }

        $maxPrefix = match (true) {
            filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false => 32,
            filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false => 128,
            default => null,
        };

        return $maxPrefix !== null && (int) $prefix <= $maxPrefix;
    }

    /**
     * Validate an allowlist and normalise it for storage: null or an empty list means no restriction and is stored as
     * null.
     *
     * @param  array<int, string>|null  $entries
     * @return array<int, string>|null
     *
     * @throws InvalidArgumentException When an entry is not an IP address or CIDR range.
     */
    public static function normalizeList(?array $entries): ?array
    {
        if ($entries === null || $entries === []) {
            return null;
        }

        foreach ($entries as $entry) {
            if (! self::isValidEntry($entry)) {
                throw new InvalidArgumentException('The IP allowlist contains an entry that is not an IP address or CIDR range.');
            }
        }

        return array_values($entries);
    }

    /**
     * Determine whether an IP address is allowed. An empty list allows every address.
     *
     * @param  array<int, string>  $entries
     */
    public static function allows(array $entries, string $ip): bool
    {
        if ($entries === []) {
            return true;
        }

        return IpUtils::checkIp($ip, $entries);
    }
}
