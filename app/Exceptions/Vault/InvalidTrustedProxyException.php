<?php

namespace App\Exceptions\Vault;

use RuntimeException;

/**
 * Thrown when TRUSTED_PROXIES holds a wildcard or an entry that is not an IP address or CIDR range.
 */
class InvalidTrustedProxyException extends RuntimeException {}
