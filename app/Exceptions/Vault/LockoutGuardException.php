<?php

namespace App\Exceptions\Vault;

use RuntimeException;

/**
 * A change that could lock people out or delete evidence, and needs a confirmation from the admin first. With a
 * `word`, the admin has to type that word; without one, a confirm is enough.
 */
class LockoutGuardException extends RuntimeException
{
    public function __construct(public readonly string $setting, string $message, public readonly ?string $word = null)
    {
        parent::__construct($message);
    }
}
