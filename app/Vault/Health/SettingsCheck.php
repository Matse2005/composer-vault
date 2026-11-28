<?php

namespace App\Vault\Health;

use App\Vault\Settings\SettingsRegistry;
use App\Vault\Settings\VaultSettings;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Fails when a stored setting no longer passes its definition (a rule can change in an upgrade) or a stored secret
 * cannot be decrypted (a wrong or rotated VAULT_KEY). Warns when a setting is set in both the environment and the
 * dashboard, because the dashboard value is ignored. It names settings, never their values.
 */
class SettingsCheck extends Check
{
    public function run(): Result
    {
        $settings = app(VaultSettings::class);
        $invalid = [];
        $undecryptable = [];
        $shadowed = [];

        foreach ($settings->storedKeys() as $key) {
            if (! SettingsRegistry::has($key)) {
                continue;
            }

            if (SettingsRegistry::get($key)->isSecret) {
                if (! $settings->secretIsFromEnvironment($key) && ! $settings->hasSecret($key)) {
                    $undecryptable[] = $key;
                }
            } elseif (! $settings->storedValueIsValid($key)) {
                $invalid[] = $key;
            }

            if ($settings->isFromEnvironment($key) || $settings->secretIsFromEnvironment($key)) {
                $shadowed[] = $key;
            }
        }

        if ($invalid !== [] || $undecryptable !== []) {
            return Result::make()
                ->failed('Stored settings need attention: '.implode(', ', [...$invalid, ...$undecryptable]).'.')
                ->shortSummary(count($invalid) + count($undecryptable).' stored setting(s) broken')
                ->meta(['fix' => $undecryptable !== []
                    ? 'A secret cannot be decrypted with the known keys. Set it again on its page, and check VAULT_KEY and VAULT_PREVIOUS_KEYS.'
                    : 'Open the page of the setting, correct the value and save, or reset it with php artisan vault:settings reset <key>.']);
        }

        if ($shadowed !== []) {
            return Result::make()
                ->warning('Set in the environment and in the dashboard, so the dashboard value is ignored: '.implode(', ', $shadowed).'.')
                ->shortSummary(count($shadowed).' setting(s) set twice')
                ->meta(['fix' => 'Remove the stored value with php artisan vault:settings reset <key>, or remove the environment variable.']);
        }

        return Result::make()->ok()->shortSummary('Stored settings are valid');
    }
}
