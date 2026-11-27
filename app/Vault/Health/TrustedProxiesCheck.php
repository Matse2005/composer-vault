<?php

namespace App\Vault\Health;

use App\Exceptions\Vault\InvalidTrustedProxyException;
use App\Vault\Tokens\TrustedProxyList;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Fails when TRUSTED_PROXIES contains a wildcard or an entry that is not an address or a range.
 */
class TrustedProxiesCheck extends Check
{
    public function run(): Result
    {
        try {
            $proxies = TrustedProxyList::parse(config('vault.trusted_proxies'));
        } catch (InvalidTrustedProxyException $exception) {
            return Result::make()
                ->failed($exception->getMessage())
                ->shortSummary('TRUSTED_PROXIES is invalid')
                ->meta(['fix' => 'List the IP addresses or CIDR ranges of your proxies in TRUSTED_PROXIES, without a wildcard.']);
        }

        return Result::make()->ok()->shortSummary($proxies === [] ? 'No proxy is trusted' : count($proxies).' proxy entries');
    }
}
