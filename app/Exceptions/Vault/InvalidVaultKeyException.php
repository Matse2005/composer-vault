<?php

namespace App\Exceptions\Vault;

use Exception;

/**
 * Thrown when a vault master key is missing or malformed. The message never contains key material.
 */
class InvalidVaultKeyException extends Exception {}
