<?php

namespace App\Vault\Sso;

use App\Exceptions\Vault\SsoRejectedException;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The "Test sign-in" of the single sign-on page. An admin signs in at the identity provider without being logged in
 * again: the vault only checks that the sign-in works and belongs to them (same verified email address). A pass is
 * remembered in their session for ten minutes, and enforcing single sign-on needs one.
 */
class SsoTestSignIn
{
    public const STARTED_KEY = 'vault.sso.test_started_for';

    public const PASSED_KEY = 'vault.sso.test_passed';

    private const VALID_MINUTES = 10;

    public function __construct(private SsoSignIn $signIn, private SsoConfig $config) {}

    public function begin(Request $request, User $actor): RedirectResponse
    {
        $request->session()->put(self::STARTED_KEY, $actor->getKey());

        return $this->signIn->begin($request);
    }

    public function isTestFor(Request $request, User $actor): bool
    {
        return $request->session()->get(self::STARTED_KEY) === $actor->getKey();
    }

    /**
     * Check the provider's answer and remember a pass.
     *
     * @throws SsoRejectedException when the sign-in fails, or is not the sign-in of this admin
     */
    public function finish(Request $request, User $actor): void
    {
        $request->session()->forget(self::STARTED_KEY);

        $claims = $this->signIn->verifiedClaims($request);
        $email = mb_strtolower(trim((string) ($claims['email'] ?? '')));

        if ($email === '' || $email !== mb_strtolower($actor->email) || ($claims['email_verified'] ?? false) !== true) {
            throw new SsoRejectedException(SsoRejectedException::NOT_PROVISIONED);
        }

        $request->session()->put(self::PASSED_KEY, ['user' => $actor->getKey(), 'at' => now()->getTimestamp()]);
    }

    /**
     * Determine whether this admin passed a test sign-in in the last ten minutes. The current request's session is used.
     */
    public function passedRecentlyBy(User $actor): bool
    {
        $passed = session(self::PASSED_KEY);

        return is_array($passed)
            && ($passed['user'] ?? null) === $actor->getKey()
            && ($passed['at'] ?? 0) >= now()->subMinutes(self::VALID_MINUTES)->getTimestamp()
            && $this->config->isComplete();
    }
}
