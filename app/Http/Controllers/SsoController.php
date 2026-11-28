<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Exceptions\Vault\SsoRejectedException;
use App\Vault\Audit\AuditLogger;
use App\Vault\Compliance\SecurityAlerts;
use App\Vault\Sso\SsoSignIn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class SsoController extends Controller
{
    /** Session key: how the admin signed in, read by the audit of the login event and by the logout. */
    public const METHOD_KEY = 'vault.auth_method';

    /** Session key: the session came from single sign-on, so a logout also ends the session at the provider. */
    public const SSO_SESSION_KEY = 'vault.sso_session';

    /** Session key: the identity provider vouched for a multi-factor sign-in, so no local second factor is asked. */
    public const SECOND_FACTOR_KEY = 'vault.second_factor_satisfied';

    /**
     * Send the browser to the identity provider.
     */
    public function redirect(Request $request, SsoSignIn $signIn, AuditLogger $auditLogger): RedirectResponse|Response
    {
        try {
            return $signIn->begin($request);
        } catch (SsoRejectedException $exception) {
            return $this->reject($exception, $auditLogger);
        }
    }

    /**
     * Finish the sign-in. Every failure gives the same page and one audit entry with a reason code.
     */
    public function callback(Request $request, SsoSignIn $signIn, AuditLogger $auditLogger): RedirectResponse|Response
    {
        try {
            $result = $signIn->handle($request);
        } catch (SsoRejectedException $exception) {
            return $this->reject($exception, $auditLogger);
        }

        $request->session()->put([self::METHOD_KEY => 'sso', self::SSO_SESSION_KEY => true]);

        if ($result->needsLocalChallenge) {
            $request->session()->put(['login.id' => $result->user->getKey(), 'login.remember' => false]);

            return redirect()->route('two-factor.login');
        }

        if ($result->mfaSatisfied) {
            $request->session()->put(self::SECOND_FACTOR_KEY, true);
        }

        Auth::login($result->user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    private function reject(SsoRejectedException $exception, AuditLogger $auditLogger): Response
    {
        $auditLogger->record(AuditAction::SsoLoginRejected, null, ['reason' => $exception->reason], null);
        app(SecurityAlerts::class)->ssoRejection();

        return response()->view('pages::auth.sso-failed', status: 403);
    }
}
