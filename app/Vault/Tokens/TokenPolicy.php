<?php

namespace App\Vault\Tokens;

use App\Exceptions\Vault\TokenPolicyException;
use App\Vault\Settings\VaultSettings;
use Carbon\CarbonInterface;

/**
 * The token lifetime policy: when a maximum lifetime is set, a token needs an expiry within it.
 */
final class TokenPolicy
{
    /**
     * Determine whether an expiry is allowed by the policy. Without a maximum, every expiry is.
     */
    public static function allows(?CarbonInterface $expiresAt): bool
    {
        $max = app(VaultSettings::class)->tokenMaxLifetimeDays();

        return $max === null || ($expiresAt !== null && $expiresAt->lte(now()->addDays($max)->endOfDay()));
    }

    /**
     * @throws TokenPolicyException when the expiry is missing or too far away
     */
    public static function assertAllows(?CarbonInterface $expiresAt): void
    {
        if (! self::allows($expiresAt)) {
            throw TokenPolicyException::expiryRequired((int) app(VaultSettings::class)->tokenMaxLifetimeDays());
        }
    }
}
