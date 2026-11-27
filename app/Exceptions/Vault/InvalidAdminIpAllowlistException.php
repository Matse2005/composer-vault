<?php

namespace App\Exceptions\Vault;

use RuntimeException;

/**
 * Thrown when VAULT_ADMIN_ALLOWED_IPS holds an entry that is not an IP address or CIDR range.
 */
class InvalidAdminIpAllowlistException extends RuntimeException {}
