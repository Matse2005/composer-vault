<?php

namespace App\Exceptions\Vault;

use Exception;

class UnknownKeyVersionException extends Exception
{
    public function __construct(public readonly int $version)
    {
        parent::__construct("No vault key is configured for version {$version}.");
    }
}
