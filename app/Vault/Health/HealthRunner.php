<?php

namespace App\Vault\Health;

use DateTimeInterface;
use Illuminate\Support\Collection;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Spatie\Health\Enums\Status;
use Spatie\Health\Health;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;
use Throwable;

/**
 * Runs every registered check now, stores the results like the package's own command does, and turns results into
 * plain rows for vault:doctor and the security status page.
 *
 * A row is ['name', 'label', 'status', 'summary', 'fix'] where the status is ok, warning, failed, crashed or skipped.
 */
class HealthRunner
{
    public function __construct(private readonly Health $health) {}

    /**
     * Run all checks fresh, including the audit chain, and store the results.
     *
     * @return Collection<int, Result>
     */
    public function runFresh(): Collection
    {
        AuditChainCheck::forget();

        $results = $this->health->registeredChecks()
            ->map(fn (Check $check): Result => $this->runCheck($check))
            ->values();

        $this->health->resultStores()->each(fn (ResultStore $store) => $store->save($results));

        return $results;
    }

    /**
     * The rows of the most recent stored run and the time it finished, or null when no run was stored yet.
     *
     * @return array{finished_at: DateTimeInterface, rows: array<int, array{name: string, label: string, status: string, summary: string, fix: string}>}|null
     */
    public function latest(): ?array
    {
        $stored = app(ResultStore::class)->latestResults();

        if ($stored === null) {
            return null;
        }

        return [
            'finished_at' => $stored->finishedAt,
            'rows' => $stored->storedCheckResults->map(self::rowFromStored(...))->values()->all(),
        ];
    }

    /**
     * The number of rows that are not ok or skipped.
     *
     * @param  array<int, array{status: string}>  $rows
     */
    public static function problemCount(array $rows): int
    {
        return count(array_filter($rows, fn (array $row): bool => ! in_array($row['status'], [Status::ok()->value, Status::skipped()->value], true)));
    }

    private function runCheck(Check $check): Result
    {
        try {
            $result = $check->run();
        } catch (Throwable $exception) {
            report($exception);

            $result = $check->markAsCrashed()->shortSummary('The check crashed');
        }

        return $result->check($check)->endedAt(now());
    }

    /**
     * @return array{name: string, label: string, status: string, summary: string, fix: string}
     */
    public static function rowFromResult(Result $result): array
    {
        return [
            'name' => $result->check->getName(),
            'label' => $result->check->getLabel(),
            'status' => (string) $result->status->value,
            'summary' => $result->getShortSummary(),
            'fix' => (string) ($result->meta['fix'] ?? ''),
        ];
    }

    /**
     * @return array{name: string, label: string, status: string, summary: string, fix: string}
     */
    public static function rowFromStored(StoredCheckResult $stored): array
    {
        return [
            'name' => $stored->name,
            'label' => $stored->label,
            'status' => $stored->status,
            'summary' => $stored->shortSummary,
            'fix' => (string) ($stored->meta['fix'] ?? ''),
        ];
    }

    /**
     * The overall outcome of rows: fail when a check failed or crashed, warn when one warns, otherwise pass.
     *
     * @param  iterable<int, array{status: string}>  $rows
     */
    public static function overall(iterable $rows): string
    {
        $statuses = collect($rows)->pluck('status');

        return match (true) {
            $statuses->contains(Status::failed()->value) || $statuses->contains(Status::crashed()->value) => 'fail',
            $statuses->contains(Status::warning()->value) => 'warn',
            default => 'pass',
        };
    }
}
