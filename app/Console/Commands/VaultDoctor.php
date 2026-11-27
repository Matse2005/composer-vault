<?php

namespace App\Console\Commands;

use App\Vault\Health\HealthRunner;
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
    public function handle(HealthRunner $runner): int
    {
        $rows = $runner->runFresh()->map(HealthRunner::rowFromResult(...))->all();
        $overall = HealthRunner::overall($rows);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'status' => $overall,
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
