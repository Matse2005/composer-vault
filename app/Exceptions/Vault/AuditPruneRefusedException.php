<?php

namespace App\Exceptions\Vault;

use RuntimeException;

class AuditPruneRefusedException extends RuntimeException
{
    public static function chainBroken(int $sequence): self
    {
        return new self("The audit log chain is broken at entry {$sequence}. Nothing was deleted.");
    }
}
