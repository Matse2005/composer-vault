<?php

namespace App\Vault\Health;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * In production, fails when the content security policy is only reported (CSP_REPORT_ONLY) and not enforced.
 */
class CspEnforcedCheck extends Check
{
    public function run(): Result
    {
        if (! app()->isProduction()) {
            return Result::make()->ok()->shortSummary('Only checked in production');
        }

        if (config('csp.report_only_presets') === []) {
            return Result::make()->ok()->shortSummary('The content security policy is enforced');
        }

        return Result::make()
            ->failed('The content security policy is only reported, not enforced.')
            ->shortSummary('CSP_REPORT_ONLY is on')
            ->meta(['fix' => 'Remove CSP_REPORT_ONLY from your environment, or set it to false.']);
    }
}
