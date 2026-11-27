<?php

namespace App\Vault\Health;

use App\Models\User;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Fails when no active admin exists, because then nobody can manage the vault.
 */
class AdminExistsCheck extends Check
{
    public function run(): Result
    {
        if (User::query()->active()->exists()) {
            return Result::make()->ok()->shortSummary('An active admin exists');
        }

        return Result::make()
            ->failed('No active admin exists.')
            ->shortSummary('No admin')
            ->meta(['fix' => 'Run php artisan vault:install to create the first admin.']);
    }
}
