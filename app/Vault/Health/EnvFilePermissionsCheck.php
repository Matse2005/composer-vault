<?php

namespace App\Vault\Health;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Fails when the .env file can be read or written by the group or by others. On Windows the permissions cannot be
 * checked, which is reported as a warning.
 */
class EnvFilePermissionsCheck extends Check
{
    public function run(): Result
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return Result::make()
                ->warning('File permissions cannot be checked on Windows.')
                ->shortSummary('Skipped on Windows');
        }

        $path = app()->environmentFilePath();

        if (! is_file($path)) {
            return Result::make()->ok()->shortSummary('No .env file, the environment comes from the server');
        }

        $permissions = fileperms($path) & 0777;

        if (($permissions & 0066) !== 0) {
            return Result::make()
                ->failed('The .env file is readable or writable by the group or by others.')
                ->shortSummary(sprintf('.env has mode %04o', $permissions))
                ->meta(['fix' => 'Run chmod 600 on the .env file.']);
        }

        return Result::make()->ok()->shortSummary(sprintf('.env has mode %04o', $permissions));
    }
}
