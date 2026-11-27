<?php

namespace App\Exceptions\Vault;

use Exception;

class HostConflictException extends Exception
{
    public function __construct(public readonly string $projectName, public readonly string $host)
    {
        parent::__construct("Project [{$projectName}] already has a credential for host [{$host}].");
    }
}
