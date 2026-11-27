<?php

namespace App\Models;

use LogicException;
use Spatie\Activitylog\Models\Activity;

/**
 * An entry of the audit log: the activity model of spatie/laravel-activitylog with a hash chain on top.
 *
 * Entries are append-only through the application and are only written by AuditLogger, which sets the chain
 * fields. Any other write fails loudly.
 *
 * @property int|null $sequence
 * @property string|null $previous_hash
 * @property string|null $hash
 * @property string|null $ip
 */
class AuditEntry extends Activity
{
    const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            if ($entry->sequence === null || $entry->hash === null) {
                throw new LogicException('Audit entries must be written through AuditLogger.');
            }
        });

        static::updating(function (): never {
            throw new LogicException('Audit entries cannot be updated.');
        });

        static::deleting(function (): never {
            throw new LogicException('Audit entries cannot be deleted.');
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), ['sequence' => 'integer']);
    }
}
