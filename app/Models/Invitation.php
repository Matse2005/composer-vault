<?php

namespace App\Models;

use App\Enums\AdminRole;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invitation for a new admin. The plain token only exists in the invitation link; the table holds its hash.
 *
 * @property string $id
 * @property string $email
 * @property AdminRole $role
 * @property string|null $custom_role_id
 * @property string $token_hash
 * @property int|null $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Hidden(['token_hash'])]
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use HasFactory, HasUlids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => AdminRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * The role the invited person gets: a role made on the Roles page, or one of the system roles.
     */
    public function resolvedRole(): Role
    {
        return $this->custom_role_id !== null ? Role::query()->findOrFail($this->custom_role_id) : Role::forAdminRole($this->role);
    }

    /**
     * Determine whether this is the invitation of the first Owner, created by vault:install. It can be accepted with a
     * password even when single sign-on is enforced, and the Owner becomes a break-glass account.
     */
    public function isFirstOwner(): bool
    {
        return $this->invited_by === null && $this->role === AdminRole::Owner;
    }

    /**
     * Determine whether the invitation can still be accepted: not accepted, not revoked and not expired.
     */
    public function isUsable(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
