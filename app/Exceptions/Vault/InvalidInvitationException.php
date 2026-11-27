<?php

namespace App\Exceptions\Vault;

use Exception;

/**
 * Thrown for every reason an invitation cannot be accepted (unknown, expired, accepted, revoked, malformed). There is
 * one class and one message on purpose, so nobody can tell which state a token is in.
 */
class InvalidInvitationException extends Exception
{
    public const MESSAGE = 'This invitation is invalid or has expired.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
