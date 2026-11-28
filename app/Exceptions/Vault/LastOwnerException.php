<?php

namespace App\Exceptions\Vault;

use RuntimeException;

class LastOwnerException extends RuntimeException
{
    public static function create(): self
    {
        return new self('At least one active Owner must remain.');
    }
}
