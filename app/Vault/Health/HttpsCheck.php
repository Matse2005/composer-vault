<?php

namespace App\Vault\Health;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Fails when the application url is not https, because credentials travel over it.
 */
class HttpsCheck extends Check
{
    public function run(): Result
    {
        if (str_starts_with((string) config('app.url'), 'https://')) {
            return Result::make()->ok()->shortSummary('APP_URL uses https');
        }

        return Result::make()
            ->failed('APP_URL does not start with https://, so credentials and tokens may travel unencrypted.')
            ->shortSummary('APP_URL is not https')
            ->meta(['fix' => 'Serve the site over HTTPS and set APP_URL to the https:// address.']);
    }
}
