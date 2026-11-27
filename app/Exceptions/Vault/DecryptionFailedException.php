<?php

namespace App\Exceptions\Vault;

use Exception;

/**
 * Thrown when a stored secret cannot be decrypted. The message is generic on purpose: it never contains
 * ciphertext, identifiers or the reason, so it is safe to log.
 */
class DecryptionFailedException extends Exception
{
    public function __construct()
    {
        parent::__construct('The secret could not be decrypted.');
    }
}
