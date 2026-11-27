<?php

namespace App\Vault\Health;

use App\Models\User;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Warns when an active admin has no second factor. It reports the number, never who.
 */
class SecondFactorCheck extends Check
{
    public function run(): Result
    {
        $without = User::query()->active()->get()->reject(fn (User $admin): bool => $admin->hasSecondFactor())->count();

        if ($without === 0) {
            return Result::make()->ok()->shortSummary('Every admin has a second factor');
        }

        return Result::make()
            ->warning("{$without} admin(s) have no second factor.")
            ->shortSummary("{$without} without a second factor")
            ->meta(['fix' => 'Ask these admins to set up an authenticator app or a passkey on the security page.']);
    }
}
