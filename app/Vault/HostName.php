<?php

namespace App\Vault;

use InvalidArgumentException;

class HostName
{
    /**
     * Reduce user input to a lowercase hostname without scheme, path or port.
     *
     * @throws InvalidArgumentException When the result is empty or is not a plain hostname.
     */
    public static function normalize(string $input): string
    {
        $host = strtolower(trim($input));
        $host = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host) ?? $host;
        $host = explode('/', $host, 2)[0];

        if (preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host) !== 1 || str_contains($host, '..')) {
            throw new InvalidArgumentException('The host must be a plain hostname without port, credentials or spaces.');
        }

        return $host;
    }
}
