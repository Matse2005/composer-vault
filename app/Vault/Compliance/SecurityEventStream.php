<?php

namespace App\Vault\Compliance;

use App\Models\AccessLog;
use App\Models\AuditEntry;
use App\Vault\Settings\VaultSettings;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the security events to a log channel as JSON lines, for a SIEM. The lines hold the fields below and nothing
 * else, so no new data leaves through this path: no audit properties, no names, no user agents. A channel that fails
 * never fails the request; the failure is remembered for the health check.
 */
class SecurityEventStream
{
    public const FAILURE_CACHE_KEY = 'vault:security-log:failed-at';

    private const FAILURE_SECONDS = 3600;

    public function __construct(private VaultSettings $settings) {}

    public function audit(AuditEntry $entry): void
    {
        $this->write([
            'type' => 'audit',
            'time' => $this->time($entry->created_at),
            'event' => $entry->event,
            'sequence' => $entry->sequence,
            'actor_id' => $entry->causer_id === null ? null : (int) $entry->causer_id,
            'subject_type' => $entry->subject_type,
            'subject_id' => $entry->subject_id === null ? null : (string) $entry->subject_id,
            'ip' => $entry->ip,
        ]);
    }

    /**
     * Write a failed API access. Successful accesses are not part of the stream.
     */
    public function access(AccessLog $log, ?string $tokenPrefix, ?string $projectSlug): void
    {
        if ($log->result->value === 'ok') {
            return;
        }

        $this->write([
            'type' => 'access',
            'time' => $this->time($log->created_at),
            'result' => $log->result->value,
            'ip' => $log->ip,
            'token_prefix' => $tokenPrefix,
            'project_slug' => $projectSlug,
        ]);
    }

    /**
     * Determine whether the channel failed in the last hour.
     */
    public static function hasFailedRecently(): bool
    {
        return Cache::has(self::FAILURE_CACHE_KEY);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function write(array $line): void
    {
        $channel = $this->settings->securityLogChannel();

        if ($channel === null) {
            return;
        }

        try {
            Log::channel($channel)->info((string) json_encode($line, JSON_UNESCAPED_SLASHES));
        } catch (Throwable) {
            Cache::put(self::FAILURE_CACHE_KEY, now()->getTimestamp(), self::FAILURE_SECONDS);
        }
    }

    private function time(?DateTimeInterface $time): ?string
    {
        return $time === null ? null : Carbon::instance($time)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
