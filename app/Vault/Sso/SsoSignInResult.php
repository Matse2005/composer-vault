<?php

namespace App\Vault\Sso;

use App\Models\User;

/**
 * The outcome of a successful single sign-on callback: the person, whether the identity provider vouched for a
 * multi-factor sign-in, and whether the person still has to complete the challenge of a local authenticator app.
 */
final readonly class SsoSignInResult
{
    public function __construct(
        public User $user,
        public bool $mfaSatisfied,
        public bool $needsLocalChallenge,
    ) {}
}
