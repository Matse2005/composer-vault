<?php

namespace App\Http\Middleware;

use App\Vault\Settings\VaultSettings;
use App\Vault\Tokens\AdminIpList;
use App\Vault\Tokens\IpAllowlist;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limits the admin interface to the addresses in vault.admin.allowed_ips. An empty list allows every address. Any
 * other address gets a plain 403 without layout or application name. The client address is the one that the trusted
 * proxy settings resolve, so behind a reverse proxy TRUSTED_PROXIES has to be set.
 *
 * It belongs to the web group only: the API and the health route have their own rules.
 */
class RestrictAdminIps
{
    public const RESTRICTED_ATTRIBUTE = 'vault.ip_restricted';

    public function handle(Request $request, Closure $next): Response
    {
        $allowedIps = AdminIpList::parse(app(VaultSettings::class)->get('admin.allowed_ips'));

        if ($allowedIps === [] || IpAllowlist::allows($allowedIps, (string) $request->ip())) {
            return $next($request);
        }

        // Break-glass Owners may bypass the list when the setting is on. Who is asking is only known after the session
        // starts, so mark the request and let EnforceBreakGlassForRestrictedIps decide.
        if ((bool) app(VaultSettings::class)->get('security.break_glass_bypasses_ip_allowlist')) {
            $request->attributes->set(self::RESTRICTED_ATTRIBUTE, true);

            return $next($request);
        }

        return response('Forbidden', 403, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
