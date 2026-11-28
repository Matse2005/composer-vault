<?php

namespace App\Vault\Health;

use App\Enums\AlertRule;
use App\Vault\Audit\AuditChainVerifier;
use App\Vault\Compliance\SecurityAlerts;
use Illuminate\Support\Facades\Cache;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Fails when the audit log hash chain is broken. Reading the whole log is costly, so a scheduled run reuses the
 * outcome of the last hour. vault:doctor and the "Run all checks now" button call forget() first to verify afresh.
 */
class AuditChainCheck extends Check
{
    public const CACHE_KEY = 'vault:health:audit-chain';

    private const CACHE_SECONDS = 3600;

    public function __construct(private readonly AuditChainVerifier $verifier)
    {
        parent::__construct();
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function run(): Result
    {
        /** @var array{broken_at: int|null} $outcome */
        $outcome = Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => ['broken_at' => $this->verifier->verify()]);

        if ($outcome['broken_at'] === null) {
            return Result::make()->ok()->shortSummary('Audit chain is intact');
        }

        app(SecurityAlerts::class)->send(AlertRule::AuditChainBreak, 'chain', "The audit log chain is broken at entry {$outcome['broken_at']}.");

        return Result::make()
            ->failed("The audit log chain is broken at entry {$outcome['broken_at']}.")
            ->shortSummary("Broken at entry {$outcome['broken_at']}")
            ->meta(['fix' => 'An audit entry was changed or removed. Restore the database from a backup and investigate who had access.']);
    }
}
