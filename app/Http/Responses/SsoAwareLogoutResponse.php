<?php

namespace App\Http\Responses;

use App\Vault\Sso\SsoLogout;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LogoutResponse;
use Laravel\Fortify\Http\Responses\LogoutResponse as FortifyLogoutResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The response after a logout: to the identity provider when the session came from single sign-on and the provider
 * has an end session endpoint, otherwise what Fortify does.
 */
class SsoAwareLogoutResponse implements LogoutResponse
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        $url = $request->attributes->get(SsoLogout::URL_ATTRIBUTE);

        if (is_string($url)) {
            return redirect()->away($url);
        }

        return (new FortifyLogoutResponse)->toResponse($request);
    }
}
