<?php

namespace App\Vault\Sso;

use App\Http\Controllers\SsoController;
use Illuminate\Auth\Events\Logout;
use Throwable;

/**
 * Works out where the browser goes after a local logout of a single sign-on session: the end session endpoint of the
 * identity provider, when it has one. The ID token is not stored, so no id_token_hint is sent; the provider shows its
 * own confirmation, and the login page is the redirect target after it.
 */
class SsoLogout
{
    public const URL_ATTRIBUTE = 'vault.sso_logout_url';

    public function __construct(private SsoProvider $provider, private SsoConfig $config) {}

    /**
     * Remember the end session url on the request. It runs while the session still exists, before Fortify clears it.
     */
    public function capture(Logout $event): void
    {
        $request = request();

        if (! $request->hasSession() || $request->session()->get(SsoController::SSO_SESSION_KEY) !== true || ! $this->config->isActive()) {
            return;
        }

        try {
            $request->attributes->set(self::URL_ATTRIBUTE, $this->provider->driver()->logoutUrl(null, route('login')));
        } catch (Throwable) {
            // The provider has no end session endpoint or cannot be reached: the logout stays local.
        }
    }
}
