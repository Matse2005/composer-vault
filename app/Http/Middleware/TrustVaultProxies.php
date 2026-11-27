<?php

namespace App\Http\Middleware;

use App\Vault\Tokens\TrustedProxyList;
use Illuminate\Http\Middleware\TrustProxies;

/**
 * Trusts the proxies listed in vault.trusted_proxies, read when the request is handled because the
 * configuration is not loaded yet while bootstrap/app.php runs.
 *
 * Without a configured proxy it trusts nobody. The parent middleware would otherwise fall back to trusting
 * every proxy on Laravel Cloud and on Forge hostnames.
 */
class TrustVaultProxies extends TrustProxies
{
    /**
     * An address that no client can have, so that "no proxies configured" is a non-empty list that trusts nobody.
     */
    private const TRUST_NOBODY = '0.0.0.0/32';

    /**
     * @return array<int, string>
     */
    protected function proxies(): array
    {
        $proxies = TrustedProxyList::parse(config('vault.trusted_proxies'));

        return $proxies === [] ? [self::TRUST_NOBODY] : $proxies;
    }
}
