<?php

namespace App\Exceptions\Vault;

use Exception;

class CannotDeactivateAdminException extends Exception
{
    public static function yourself(): self
    {
        return new self(__('You cannot deactivate yourself.'));
    }
}
