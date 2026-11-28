<?php

namespace App\Exceptions\Vault;

use RuntimeException;

/**
 * Thrown instead of applying a change that now waits for the approval of a second Owner. It is not an error: the
 * caller tells the admin the change was proposed.
 */
class ChangeProposedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('The change was proposed. Another Owner has to approve it within 24 hours.'));
    }
}
