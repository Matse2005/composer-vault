<?php

namespace App\Exceptions\Vault;

use RuntimeException;

class BreakGlassNotAllowedException extends RuntimeException
{
    public static function notAnOwner(): self
    {
        return new self(__('Only an active Owner can be a break-glass account.'));
    }

    public static function tooMany(): self
    {
        return new self(__('At most two break-glass accounts are allowed.'));
    }
}
