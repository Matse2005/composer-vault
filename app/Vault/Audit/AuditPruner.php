<?php

namespace App\Vault\Audit;

use App\Enums\AuditAction;
use App\Exceptions\Vault\AuditPruneRefusedException;
use App\Models\AuditEntry;
use Illuminate\Support\Facades\DB;

/**
 * Removes audit entries that are older than the retention period. It is the only code that deletes audit entries: the
 * model refuses deletes, and an architecture test keeps this the only place that touches the table that way.
 *
 * The chain is verified over the entries first, a checkpoint with the last removed entry is written, and the prune is
 * itself audited. Nothing is deleted when the chain is broken. The latest entry is never removed.
 */
class AuditPruner
{
    public function __construct(private AuditChainVerifier $verifier, private AuditLogger $auditLogger) {}

    /**
     * Remove the entries older than the given number of days.
     *
     * @return int the number of entries removed
     *
     * @throws AuditPruneRefusedException when the chain over the entries is broken
     */
    public function prune(int $retentionDays): int
    {
        return DB::transaction(function () use ($retentionDays): int {
            $head = DB::table('audit_log_head')->where('id', 1)->lockForUpdate()->first();
            $latest = $head === null ? 0 : (int) $head->sequence;

            $boundary = $this->firstSequenceToKeep($retentionDays, $latest);
            $lastToRemove = $boundary - 1;

            [$startSequence, $startHash] = $this->verifier->start();

            if ($lastToRemove <= $startSequence) {
                return 0;
            }

            $walk = $this->verifier->walk($startSequence, $startHash, $lastToRemove);

            if ($walk['broken'] !== null) {
                throw AuditPruneRefusedException::chainBroken($walk['broken']);
            }

            $count = $lastToRemove - $startSequence;

            DB::table('audit_log_checkpoints')->insert([
                'sequence' => $lastToRemove,
                'hash' => $walk['last_hash'],
                'pruned_count' => $count,
                'created_at' => now(),
            ]);

            DB::table((new AuditEntry)->getTable())
                ->where('log_name', AuditLogger::LOG_NAME)
                ->where('sequence', '<=', $lastToRemove)
                ->delete();

            $this->auditLogger->record(AuditAction::AuditLogPruned, null, ['pruned_count' => $count, 'checkpoint_sequence' => $lastToRemove], null);

            return $count;
        });
    }

    /**
     * Get the sequence of the first entry that stays: the first one that is newer than the cutoff, or the latest
     * entry when every entry is old.
     */
    private function firstSequenceToKeep(int $retentionDays, int $latest): int
    {
        $firstRecent = AuditEntry::query()
            ->where('created_at', '>=', now()->subDays($retentionDays))
            ->orderBy('sequence')
            ->value('sequence');

        return $firstRecent === null ? $latest : min((int) $firstRecent, $latest);
    }
}
