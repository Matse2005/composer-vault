<?php

namespace App\Vault\Health;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Fails when the sodium extension is missing, because every secret is encrypted with it.
 */
class SodiumCheck extends Check
{
    public function run(): Result
    {
        if (extension_loaded('sodium')) {
            return Result::make()->ok()->shortSummary('sodium is loaded');
        }

        return Result::make()
            ->failed('The sodium PHP extension is not loaded, so secrets cannot be encrypted or decrypted.')
            ->shortSummary('sodium is missing')
            ->meta(['fix' => 'Install and enable the sodium extension for the PHP version of this site.']);
    }
}
