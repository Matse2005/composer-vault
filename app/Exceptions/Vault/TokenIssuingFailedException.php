<?php

namespace App\Exceptions\Vault;

use RuntimeException;

/**
 * Thrown when no free token prefix was found after the allowed number of attempts.
 */
class TokenIssuingFailedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('An access token could not be issued because no free token prefix was found.');
    }
}
