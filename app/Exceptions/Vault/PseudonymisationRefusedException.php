<?php

namespace App\Exceptions\Vault;

use RuntimeException;

class PseudonymisationRefusedException extends RuntimeException
{
    public static function stillActive(): self
    {
        return new self(__('Only a deactivated admin can be pseudonymised. Deactivate the admin first.'));
    }
}
