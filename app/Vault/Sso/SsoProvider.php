<?php

namespace App\Vault\Sso;

use GuzzleHttp\Client;
use JeffersonGoncalves\LaravelOidc\Data\OidcConfig;
use JeffersonGoncalves\LaravelOidc\Providers\GenericOidcProvider;
use Laravel\Socialite\Facades\Socialite;

/**
 * Builds the Socialite OpenID Connect driver with the settings of the dashboard, at runtime. The driver does the
 * discovery, the PKCE and nonce handling and the ID token validation; this class only feeds it the configuration.
 */
class SsoProvider
{
    public function __construct(private SsoConfig $config) {}

    public function driver(): GenericOidcProvider
    {
        // The manager keeps drivers for the rest of the process and a driver keeps its request, so start fresh.
        Socialite::forgetDrivers();

        /** @var GenericOidcProvider $driver */
        $driver = Socialite::driver('oidc');

        // Tests bind their own client under this name; otherwise the requests get a timeout.
        $driver->setHttpClient(app()->bound('vault.sso.http') ? app('vault.sso.http') : new Client(['timeout' => 10, 'connect_timeout' => 5]));

        return $driver->setConfig(new OidcConfig(
            issuerUrl: $this->config->issuer(),
            clientId: $this->config->clientId(),
            clientSecret: $this->config->clientSecret(),
            redirectUri: route('sso.callback'),
            scopes: $this->config->scopes(),
        ));
    }
}
