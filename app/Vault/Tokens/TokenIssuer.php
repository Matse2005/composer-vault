<?php

namespace App\Vault\Tokens;

use App\Exceptions\Vault\TokenIssuingFailedException;
use App\Models\AccessToken;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class TokenIssuer
{
    private const MAX_ATTEMPTS = 3;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    private const RANDOM_LENGTH = 40;

    private const PREFIX_LENGTH = 8;

    /**
     * Generate a plain token: "cv_" and 40 characters from [A-Za-z0-9].
     *
     * Every character comes from its own random_int call, so the choice is unbiased.
     */
    public function generatePlainToken(): string
    {
        $random = '';

        for ($position = 0; $position < self::RANDOM_LENGTH; $position++) {
            $random .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return 'cv_'.$random;
    }

    /**
     * Get the lookup prefix of a plain token: the first 8 characters after "cv_".
     */
    public static function prefix(string $plainToken): string
    {
        return substr($plainToken, 3, self::PREFIX_LENGTH);
    }

    /**
     * Get the stored hash of a plain token. SHA-256 is enough because the token has 40 random characters.
     */
    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * Give the token a new plain value and save it. Each attempt runs in its own savepoint, so a prefix
     * collision does not abort an outer transaction.
     *
     * @throws TokenIssuingFailedException After three prefix collisions.
     */
    public function issue(AccessToken $token): IssuedToken
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $plainToken = $this->generatePlainToken();

            $token->forceFill([
                'token_prefix' => self::prefix($plainToken),
                'token_hash' => self::hash($plainToken),
            ]);

            try {
                DB::transaction(fn () => $token->save());

                return new IssuedToken($token, $plainToken);
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        throw new TokenIssuingFailedException;
    }
}
