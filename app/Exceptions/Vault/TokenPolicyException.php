<?php

namespace App\Exceptions\Vault;

use RuntimeException;

class TokenPolicyException extends RuntimeException
{
    public static function expiryRequired(int $maxDays): self
    {
        return new self(__('A token needs an expiry date within :days days.', ['days' => $maxDays]));
    }
}
