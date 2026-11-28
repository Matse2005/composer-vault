<?php

namespace App\Exceptions\Vault;

use RuntimeException;

class ApprovalRefusedException extends RuntimeException
{
    public static function ownChange(): self
    {
        return new self(__('You cannot approve your own change. Another Owner has to.'));
    }

    public static function expired(): self
    {
        return new self(__('This change expired after 24 hours. Propose it again.'));
    }

    public static function alreadyDecided(): self
    {
        return new self(__('This change was decided already.'));
    }
}
