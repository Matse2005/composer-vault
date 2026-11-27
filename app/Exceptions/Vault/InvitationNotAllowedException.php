<?php

namespace App\Exceptions\Vault;

use Exception;

/**
 * Thrown when an admin is invited with an email address that already belongs to an admin or already has a usable
 * invitation. Only admins can cause this, so the message may be specific.
 */
class InvitationNotAllowedException extends Exception {}
