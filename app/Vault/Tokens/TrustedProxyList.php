<?php

namespace App\Vault\Tokens;

use App\Exceptions\Vault\InvalidTrustedProxyException;

class TrustedProxyList
{
    /**
     * Parse the comma separated TRUSTED_PROXIES value.
     *
     * A wildcard is refused on purpose: it would let any client set its own IP address through a forwarding
     * header and so bypass every token IP allowlist.
     *
     * @return array<int, string>
     *
     * @throws InvalidTrustedProxyException
     */
    public static function parse(?string $configured): array
    {
        $entries = array_values(array_filter(
            array_map(trim(...), explode(',', (string) $configured)),
            fn (string $entry): bool => $entry !== '',
        ));

        foreach ($entries as $entry) {
            if (preg_match('/^\*+$/', $entry) === 1) {
                throw new InvalidTrustedProxyException('TRUSTED_PROXIES must not contain a wildcard: it would let any client spoof its IP address and bypass the token IP allowlists. List the IP addresses or CIDR ranges of your proxies instead.');
            }

            if (! IpAllowlist::isValidEntry($entry)) {
                throw new InvalidTrustedProxyException("TRUSTED_PROXIES contains an entry that is not an IP address or CIDR range: [{$entry}].");
            }
        }

        return $entries;
    }
}
