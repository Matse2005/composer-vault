<?php

namespace App\Models;

use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A record of a security incident: when it was detected, what is known, and when it was closed. The notes are free
 * text written by an Owner and are never copied into the audit log.
 *
 * @property string $id
 * @property string $title
 * @property Carbon $detected_at
 * @property Carbon|null $closed_at
 * @property string|null $notes
 * @property int|null $created_by
 */
class Incident extends Model
{
    /** @use HasFactory<IncidentFactory> */
    use HasFactory, HasUlids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * The reference times counted from the moment of detection: 24 hours, 72 hours and one month. They are points to
     * check the reporting duties against, not a statement of what the company must report.
     *
     * @return array<string, Carbon>
     */
    public function referenceTimes(): array
    {
        return [
            'After 24 hours' => $this->detected_at->copy()->addHours(24),
            'After 72 hours' => $this->detected_at->copy()->addHours(72),
            'After one month' => $this->detected_at->copy()->addMonth(),
        ];
    }
}
