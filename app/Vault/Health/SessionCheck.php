<?php

namespace App\Vault\Health;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * In production, fails when the session cookie is not secure or not same-site strict.
 */
class SessionCheck extends Check
{
    public function run(): Result
    {
        if (! app()->isProduction()) {
            return Result::make()->ok()->shortSummary('Only checked in production');
        }

        $problems = [];

        if (config('session.secure') !== true) {
            $problems[] = 'SESSION_SECURE_COOKIE is not true';
        }

        if (config('session.same_site') !== 'strict') {
            $problems[] = 'SESSION_SAME_SITE is not strict';
        }

        if ($problems === []) {
            return Result::make()->ok()->shortSummary('Session cookie is secure and strict');
        }

        return Result::make()
            ->failed('The session cookie is not hardened: '.implode(', ', $problems).'.')
            ->shortSummary(implode(', ', $problems))
            ->meta(['fix' => 'Set SESSION_SECURE_COOKIE=true and SESSION_SAME_SITE=strict in your environment.']);
    }
}
