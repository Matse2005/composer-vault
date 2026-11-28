<?php

namespace App\Vault\Audit;

use App\Enums\AuditAction;
use App\Models\AuditEntry;
use App\Models\User;
use App\Vault\Compliance\SecurityEventStream;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only way to write an audit entry. It appends to the hash chain under a lock on the head row, so the sequence
 * and the previous hash are never used twice. Every admin change calls it inside the transaction of the change.
 */
class AuditLogger
{
    public const LOG_NAME = 'audit';

    /**
     * Write one audit entry.
     *
     * The changes are sanitized and must not hold names or email addresses: the actor is the causer, and admins are
     * deactivated, never deleted, so personal data stays out of the chain. For a deleted subject, pass a label that
     * keeps the entry readable (for example the project name).
     *
     * @param  array<array-key, mixed>  $changes
     */
    public function record(
        AuditAction $action,
        ?Model $subject,
        array $changes = [],
        ?User $actor = null,
        ?string $subjectLabel = null,
    ): AuditEntry {
        return DB::transaction(function () use ($action, $subject, $changes, $actor, $subjectLabel): AuditEntry {
            $head = DB::table('audit_log_head')->where('id', 1)->lockForUpdate()->first();

            if ($head === null) {
                throw new RuntimeException('The audit log head row is missing.');
            }

            $sequence = (int) $head->sequence + 1;
            $previousHash = (string) $head->last_hash;
            $description = $action->description();

            $properties = AuditChanges::sanitize($changes);

            if ($subjectLabel !== null) {
                $properties['subject_label'] = $subjectLabel;
            }

            $logger = activity(self::LOG_NAME)->event($action->value)->withProperties($properties);

            if ($subject !== null) {
                $logger->performedOn($subject);
            }

            $logger->causedBy($actor ?? Auth::user());

            $logger->tap(function (AuditEntry $entry) use ($sequence, $previousHash, $description): void {
                $entry->sequence = $sequence;
                $entry->previous_hash = $previousHash;
                $entry->ip = request()->ip();
                $entry->created_at = Carbon::now('UTC')->startOfSecond();
                $entry->description = $description;
                $entry->hash = AuditHasher::hash($previousHash, AuditHasher::fieldsOf($entry));
            });

            $entry = $logger->log($description);

            if (! $entry instanceof AuditEntry) {
                throw new RuntimeException('The audit entry was not written. Is activity logging disabled?');
            }

            DB::table('audit_log_head')->where('id', 1)->update([
                'sequence' => $sequence,
                'last_hash' => $entry->hash,
            ]);

            DB::afterCommit(fn () => app(SecurityEventStream::class)->audit($entry));

            return $entry;
        });
    }
}
