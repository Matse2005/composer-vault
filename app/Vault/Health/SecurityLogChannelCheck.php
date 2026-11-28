<?php

namespace App\Vault\Health;

use App\Vault\Compliance\SecurityEventStream;
use App\Vault\Settings\VaultSettings;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Warns when the security log channel is set but unknown, or failed to write in the last hour.
 */
class SecurityLogChannelCheck extends Check
{
    public function run(): Result
    {
        $channel = app(VaultSettings::class)->securityLogChannel();

        if ($channel === null) {
            return Result::make()->ok()->shortSummary('Security event stream is off');
        }

        if (config("logging.channels.{$channel}") === null) {
            return Result::make()->warning("The security log channel \"{$channel}\" is not defined in config/logging.php.")->shortSummary('Unknown channel');
        }

        if (SecurityEventStream::hasFailedRecently()) {
            return Result::make()->warning('Writing to the security log channel failed in the last hour.')->shortSummary('Writing failed')
                ->meta(['fix' => 'Check the channel in config/logging.php and that its destination is reachable and writable.']);
        }

        return Result::make()->ok()->shortSummary('Security event stream works');
    }
}
