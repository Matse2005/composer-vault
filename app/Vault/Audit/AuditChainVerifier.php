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
     * the previous plus one, a previous hash that differs from the hash of the previous entry, or a hash that does
     * not match the entry. The chain starts at the latest checkpoint (the last entry that retention removed) or, when
     * there is none, at the first entry, which must have sequence 1: removed entries without a checkpoint are a
     * break. Finally the head row must match the last entry.
     */
    public function verify(): ?int
    {
        [$sequence, $hash] = $this->start();

        $walk = $this->walk($sequence, $hash);

        if ($walk['broken'] !== null) {
            return $walk['broken'];
        }

        return $this->headMismatch($walk['last_sequence'], $walk['last_hash']);
    }

    /**
     * Get the sequence and hash the chain continues from: the latest checkpoint, or the start of the log.
     *
     * @return array{0: int, 1: string} the sequence and hash of the last entry before the first one to verify
     */
    public function start(): array
    {
        $checkpoint = DB::table('audit_log_checkpoints')->orderByDesc('sequence')->first();

        return $checkpoint === null ? [0, AuditHasher::GENESIS_HASH] : [(int) $checkpoint->sequence, (string) $checkpoint->hash];
    }

    /**
     * Walk the entries after a sequence and check each link, optionally stopping at a sequence.
     *
     * @return array{broken: int|null, last_sequence: int, last_hash: string}
     */
    public function walk(int $afterSequence, string $previousHash, ?int $upToSequence = null): array
    {
        $expectedSequence = $afterSequence + 1;
        $broken = null;

        AuditEntry::query()
            ->where(fn ($query) => $query->where('sequence', '>', $afterSequence)->orWhereNull('sequence'))
            ->when($upToSequence !== null, fn ($query) => $query->where(fn ($inner) => $inner->where('sequence', '<=', $upToSequence)->orWhereNull('sequence')))
            ->orderBy('sequence')
            ->chunk(self::CHUNK_SIZE, function ($entries) use (&$expectedSequence, &$previousHash, &$broken): bool {
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

        return ['broken' => $broken, 'last_sequence' => $expectedSequence - 1, 'last_hash' => $previousHash];
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
