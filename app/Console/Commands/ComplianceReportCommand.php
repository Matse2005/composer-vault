<?php

namespace App\Console\Commands;

use App\Vault\Compliance\ComplianceReport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('vault:compliance-report {--json : Print the report as JSON}')]
#[Description('Show which controls the vault provides and their current state')]
class ComplianceReportCommand extends Command
{
    public function handle(ComplianceReport $report): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line(ComplianceReport::DISCLAIMER);
        $this->newLine();
        $this->table(
            ['Control', 'Status', 'Evidence', 'Supports'],
            array_map(fn (array $row): array => [$row['control'], $row['status']->label(), $row['evidence'], $row['supports']], $report->controls()),
        );

        return self::SUCCESS;
    }
}
