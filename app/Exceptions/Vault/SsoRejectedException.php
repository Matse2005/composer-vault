<?php

namespace App\Exceptions\Vault;

use RuntimeException;

/**
 * A single sign-on attempt that was refused. The reason is a short code for the audit log. It never holds a token, a
 * claim value or a name, and the person only ever sees the same "Sign-in failed" page.
 */
class SsoRejectedException extends RuntimeException
{
    public const PROVIDER_ERROR = 'provider_error';

    public const DISCOVERY_FAILED = 'discovery_failed';

    public const STATE_MISSING = 'state_missing';

    public const STATE_EXPIRED = 'state_expired';

    public const STATE_MISMATCH = 'state_mismatch';

    public const CODE_MISSING = 'code_missing';

    public const TOKEN_EXCHANGE_FAILED = 'token_exchange_failed';

    public const INVALID_ID_TOKEN = 'invalid_id_token';

    public const EMAIL_MISSING = 'email_missing';

    public const EMAIL_UNVERIFIED = 'email_unverified';

    public const DOMAIN_NOT_ALLOWED = 'domain_not_allowed';

    public const NO_ROLE = 'no_role';

    public const NOT_PROVISIONED = 'not_provisioned';

    public const DEACTIVATED = 'deactivated';

    public const SECOND_FACTOR_REQUIRED = 'second_factor_required';

    public const NOT_CONFIGURED = 'not_configured';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('Sign-in failed.');
    }
}
