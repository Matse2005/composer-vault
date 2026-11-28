<?php

namespace App\Models;

use Database\Factories\AccessReviewFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A completed review of who and what has access. The snapshot holds counts only.
 *
 * @property string $id
 * @property int|null $completed_by
 * @property Carbon $completed_at
 * @property string|null $notes
 * @property array<string, int> $snapshot
 */
class AccessReview extends Model
{
    /** @use HasFactory<AccessReviewFactory> */
    use HasFactory, HasUlids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'snapshot' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
