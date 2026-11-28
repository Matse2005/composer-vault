<?php

namespace App\Console\Commands;

use App\Vault\Health\HealthRunner;
use App\Vault\Settings\SettingsRegistry;
use App\Vault\Settings\VaultSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('vault:doctor {--json : Print the result as JSON} {--strict : Exit with 1 on warnings too}')]
#[Description('Run all health checks now and report them')]
class VaultDoctor extends Command
{
    /**
     * Run every check fresh and store the results. The exit code is 1 when a check fails, or when one warns with
     * --strict, so a deploy script can stop on it.
     */
    /**
     * The source of every security-relevant setting: environment, dashboard or default.
     *
     * @return array<string, string>
     */
    private function settingSources(): array
    {
        $settings = app(VaultSettings::class);
        $sources = [];

        foreach (SettingsRegistry::all() as $key => $definition) {
            if (str_starts_with($key, 'sso.') || str_starts_with($key, 'security.') || str_starts_with($key, 'retention.') || $key === 'admin.allowed_ips' || $key === 'approval.enabled') {
                $sources[$key] = $settings->source($key);
            }
        }

        return $sources;
    }

    public function handle(HealthRunner $runner): int
    {
        $rows = $runner->runFresh()->map(HealthRunner::rowFromResult(...))->all();
        $overall = HealthRunner::overall($rows);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'status' => $overall,
                'settings' => $this->settingSources(),
                'checks' => array_map(fn (array $row): array => [
                    'name' => $row['name'],
                    'status' => $row['status'],
                    'summary' => $row['summary'],
                    'fix' => $row['fix'],
                ], $rows),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Composer Vault '.config('vault.version'));
            $this->table(
                ['Status', 'Check', 'Summary', 'Fix'],
                array_map(fn (array $row): array => [$row['status'], $row['label'], $row['summary'], $row['fix']], $rows),
            );
        }

        return $overall === 'fail' || ($overall === 'warn' && $this->option('strict')) ? self::FAILURE : self::SUCCESS;
    }
}
