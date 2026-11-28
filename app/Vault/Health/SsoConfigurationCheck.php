<?php

namespace App\Vault\Health;

use App\Enums\AdminRole;
use App\Models\User;
use App\Vault\Sso\SsoConfig;
use JeffersonGoncalves\LaravelOidc\Services\OidcDiscoveryService;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Throwable;

/**
 * Checks single sign-on when it is on: the settings are complete and the provider answers discovery. Warns when it is
 * enforced without a break-glass account, or with more than two.
 */
class SsoConfigurationCheck extends Check
{
    public function run(): Result
    {
        $config = app(SsoConfig::class);

        if (! $config->enabled()) {
            return Result::make()->ok()->shortSummary('Single sign-on is off');
        }

        if (! $config->isComplete()) {
            return Result::make()->failed('Single sign-on is on but the issuer, client ID or client secret is missing.')->shortSummary('Incomplete')
                ->meta(['fix' => 'Complete the Single sign-on page, or turn single sign-on off.']);
        }

        try {
            app(OidcDiscoveryService::class)->discover($config->issuer());
        } catch (Throwable) {
            return Result::make()->failed('The identity provider did not answer discovery.')->shortSummary('Discovery failed')
                ->meta(['fix' => 'Check the issuer URL and that the provider is reachable from this server.']);
        }

        if (! $config->enforced()) {
            return Result::make()->ok()->shortSummary('Single sign-on works');
        }

        $breakGlass = User::query()->active()->where('is_break_glass', true)->where('role', AdminRole::Owner->value)->count();

        if ($breakGlass === 0) {
            return Result::make()->warning('Single sign-on is enforced and there is no break-glass Owner.')->shortSummary('No break-glass account')
                ->meta(['fix' => 'Mark an Owner as break-glass on the Admins page, so the vault stays reachable when the provider is down.']);
        }

        if ($breakGlass > 2) {
            return Result::make()->warning("{$breakGlass} break-glass accounts exist. Keep at most two.")->shortSummary("{$breakGlass} break-glass accounts");
        }

        return Result::make()->ok()->shortSummary('Single sign-on is enforced');
    }
}
