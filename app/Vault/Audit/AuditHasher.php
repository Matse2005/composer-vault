<?php

namespace App\Vault\Audit;

use App\Models\AuditEntry;
use Illuminate\Support\Carbon;

/**
 * Computes the hash of an audit entry. Both AuditLogger and AuditChainVerifier use it, so they cannot drift apart.
 */
final class AuditHasher
{
    /** The previous hash of the first entry. */
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * @param  array<string, mixed>  $fields  See fieldsOf().
     */
    public static function hash(string $previousHash, array $fields): string
    {
        return hash('sha256', $previousHash."\n".CanonicalJson::encode($fields));
    }

    /**
     * Get the hashed fields of an entry. Identifiers are cast to strings and the properties are read back as an
     * array, so the result does not depend on how a database returns integers or reorders JSON keys.
     *
     * @return array<string, mixed>
     */
    public static function fieldsOf(AuditEntry $entry): array
    {
        $causerId = $entry->causer_id;
        $subjectId = $entry->subject_id;

        return [
            'sequence' => (int) $entry->sequence,
            'log_name' => $entry->log_name,
            'event' => $entry->event,
            'description' => $entry->description,
            'subject_type' => $entry->subject_type,
            'subject_id' => $subjectId === null ? null : (string) $subjectId,
            'causer_type' => $entry->causer_type,
            'causer_id' => $causerId === null ? null : (string) $causerId,
            'properties' => $entry->properties?->toArray() ?? [],
            'ip' => $entry->ip,
            'created_at' => $entry->created_at === null ? null : Carbon::instance($entry->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
