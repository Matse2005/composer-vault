<?php

namespace App\Vault\Audit;

use App\Models\AuditEntry;
use Illuminate\Support\Facades\DB;

/**
 * Walks the audit log by sequence and checks the hash chain.
 */
class AuditChainVerifier
{
    private const CHUNK_SIZE = 500;

    /**
     * Verify the chain.
     *
     * Returns null when the chain is intact, otherwise the first sequence where it breaks: a sequence that is not
     * the previous plus one (the first must be 1), a previous hash that differs from the hash of the previous
     * entry, or a hash that does not match the entry. Finally the head row must match the last entry.
     */
    public function verify(): ?int
    {
        $expectedSequence = 1;
        $previousHash = AuditHasher::GENESIS_HASH;
        $broken = null;

        AuditEntry::query()->orderBy('sequence')->chunk(self::CHUNK_SIZE, function ($entries) use (&$expectedSequence, &$previousHash, &$broken): bool {
            foreach ($entries as $entry) {
                if ($entry->sequence !== $expectedSequence
                    || $entry->previous_hash !== $previousHash
                    || ! hash_equals((string) $entry->hash, AuditHasher::hash($previousHash, AuditHasher::fieldsOf($entry)))) {
                    $broken = $entry->sequence ?? $expectedSequence;

                    return false;
                }

                $previousHash = (string) $entry->hash;
                $expectedSequence++;
            }

            return true;
        });

        if ($broken !== null) {
            return $broken;
        }

        return $this->headMismatch($expectedSequence - 1, $previousHash);
    }

    /**
     * Compare the head row with the last verified entry.
     */
    private function headMismatch(int $lastSequence, string $lastHash): ?int
    {
        $head = DB::table('audit_log_head')->where('id', 1)->first();

        if ($head === null) {
            return max($lastSequence, 1);
        }

        $headSequence = (int) $head->sequence;

        if ($headSequence !== $lastSequence) {
            return min($headSequence, $lastSequence) + 1;
        }

        return $head->last_hash === $lastHash ? null : max($lastSequence, 1);
    }
}
