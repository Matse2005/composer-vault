<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A critical change that waits for a second Owner. The payload holds the proposed value; a secret is stored encrypted.
 *
 * @property string $id
 * @property string $kind setting, secret, role or owner
 * @property string $subject a setting key, a role id or an admin id
 * @property array<string, mixed> $payload
 * @property int|null $proposed_by
 * @property string $status pending, approved, rejected or expired
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon $expires_at
 */
class PendingChange extends Model
{
    use HasUlids;

    protected $table = 'pending_setting_changes';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isFuture();
    }
}
