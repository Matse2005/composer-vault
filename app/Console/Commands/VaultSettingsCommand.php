<?php

namespace App\Console\Commands;

use App\Actions\Vault\SetLockdown;
use App\Enums\AuditAction;
use App\Models\VaultSetting;
use App\Vault\Audit\AuditLogger;
use App\Vault\Settings\SettingsRegistry;
use App\Vault\Settings\VaultSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\password;

#[Signature('vault:settings {action : list, get, set or reset} {key? : The setting, for example admin.allowed_ips} {value? : The new value, for set} {--lockout : With reset, open up the admin IP allowlist and turn off single sign-on enforcement and lockdown}')]
#[Description('Show, change or reset the settings of the dashboard from the console. This is the recovery path.')]
class VaultSettingsCommand extends Command
{
    public function handle(VaultSettings $settings, AuditLogger $auditLogger): int
    {
        return match ($this->argument('action')) {
            'list' => $this->list($settings),
            'get' => $this->get($settings),
            'set' => $this->set($settings),
            'reset' => $this->reset($settings, $auditLogger),
            default => $this->refuse('The action must be list, get, set or reset.'),
        };
    }

    private function list(VaultSettings $settings): int
    {
        $rows = [];

        foreach (SettingsRegistry::all() as $key => $definition) {
            $rows[] = [$key, $settings->source($key), $this->display($settings, $key)];
        }

        $this->table(['Setting', 'Source', 'Value'], $rows);

        return self::SUCCESS;
    }

    private function get(VaultSettings $settings): int
    {
        $key = $this->key();

        if ($key === null) {
            return self::FAILURE;
        }

        $this->line($this->display($settings, $key));

        return self::SUCCESS;
    }

    private function set(VaultSettings $settings): int
    {
        $key = $this->key();

        if ($key === null) {
            return self::FAILURE;
        }

        if ($settings->source($key) === 'environment') {
            return $this->refuse("{$key} is set by the environment variable ".(SettingsRegistry::get($key)->envVariable ?? '').'. Change it there, or the stored value would be ignored.');
        }

        if (SettingsRegistry::get($key)->isSecret) {
            if (! $this->input->isInteractive()) {
                return $this->refuse('A secret cannot be set without interaction: it would end up in a shell history or a process list. Run the command in a terminal.');
            }

            $settings->setSecretFromConsole($key, password("New value for {$key} (hidden)", required: true));
            $this->components->info("{$key} was stored, encrypted.");

            return self::SUCCESS;
        }

        $value = $this->argument('value');

        if ($value === null) {
            return $this->refuse('Give the new value as the third argument.');
        }

        try {
            $settings->updateFromConsole([$key => $this->coerce($key, (string) $value)]);
        } catch (ValidationException $exception) {
            return $this->refuse((string) collect($exception->errors())->flatten()->first());
        }

        $this->components->info("{$key} was changed.");

        return self::SUCCESS;
    }

    private function reset(VaultSettings $settings, AuditLogger $auditLogger): int
    {
        if ($this->option('lockout')) {
            $settings->updateFromConsole(['admin.allowed_ips' => '', 'sso.enforce' => false]);

            if (SetLockdown::isOn()) {
                VaultSetting::query()->whereKey(SetLockdown::KEY)->delete();
                $auditLogger->record(AuditAction::LockdownChanged, null, ['lockdown' => false, 'source' => 'console']);
            }

            $this->components->info('The admin IP allowlist is open, single sign-on enforcement is off and the lockdown is lifted. Log in and fix the settings.');

            return self::SUCCESS;
        }

        $key = $this->key();

        if ($key === null) {
            return self::FAILURE;
        }

        $settings->resetFromConsole($key);
        $this->components->info("{$key} was reset to the environment value or its default.");

        return self::SUCCESS;
    }

    private function key(): ?string
    {
        $key = $this->argument('key');

        if (! is_string($key) || ! SettingsRegistry::has($key)) {
            $this->components->error('Name a setting from "vault:settings list".');

            return null;
        }

        return $key;
    }

    private function display(VaultSettings $settings, string $key): string
    {
        if (SettingsRegistry::get($key)->isSecret) {
            return $settings->hasSecret($key) ? 'set' : 'not set';
        }

        $value = $settings->get($key);

        return match (true) {
            is_bool($value) => $value ? 'on' : 'off',
            $value === null || $value === '' => '-',
            default => (string) $value,
        };
    }

    private function coerce(string $key, string $value): mixed
    {
        if (is_bool(VaultSettings::defaults()[$key] ?? null)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return $value;
    }

    private function refuse(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }
}
