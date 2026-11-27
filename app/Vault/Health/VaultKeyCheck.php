<?php

namespace App\Vault\Health;

use App\Exceptions\Vault\InvalidVaultKeyException;
use App\Vault\Crypto\KeyProvider;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Fails when VAULT_KEY is missing or invalid, or when it is the same value as APP_KEY. The two keys protect different
 * things and must not be shared. Messages never contain key material.
 */
class VaultKeyCheck extends Check
{
    public function __construct(private readonly KeyProvider $keyProvider)
    {
        parent::__construct();
    }

    public function run(): Result
    {
        try {
            $this->keyProvider->current();
        } catch (InvalidVaultKeyException $exception) {
            return Result::make()
                ->failed($exception->getMessage())
                ->shortSummary('VAULT_KEY is not usable')
                ->meta(['fix' => 'Set VAULT_KEY to base64: followed by 32 random bytes in base64, and VAULT_KEY_VERSION to a positive number.']);
        }

        $vaultKey = (string) config('vault.key');
        $appKey = (string) config('app.key');

        if ($appKey !== '' && hash_equals($appKey, $vaultKey)) {
            return Result::make()
                ->failed('VAULT_KEY is identical to APP_KEY.')
                ->shortSummary('VAULT_KEY equals APP_KEY')
                ->meta(['fix' => 'Generate a separate VAULT_KEY. Changing it needs vault:rotate-key when credentials exist.']);
        }

        return Result::make()->ok()->shortSummary('VAULT_KEY is valid and separate from APP_KEY');
    }
}
