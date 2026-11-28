<?php

namespace App\Models;

use App\Enums\AdminRole;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A role: a name and a set of permissions from the fixed list. The three system roles (Owner, Admin, Auditor) have the
 * slug of their AdminRole value. Owner always holds every permission and cannot be edited or deleted.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property bool $is_system
 * @property list<string> $permissions
 * @property list<string>|null $sso_groups
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory, HasUlids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'permissions' => 'array',
            'sso_groups' => 'array',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'custom_role_id');
    }

    public function isOwner(): bool
    {
        return $this->is_system && $this->slug === AdminRole::Owner->value;
    }

    /**
     * Get the AdminRole value users with this role have: the system role itself, or Custom.
     */
    public function adminRole(): AdminRole
    {
        return $this->is_system ? (AdminRole::tryFrom($this->slug) ?? AdminRole::Custom) : AdminRole::Custom;
    }

    /**
     * Give a user this role.
     */
    public function assignTo(User $user): void
    {
        $user->forceFill([
            'role' => $this->adminRole(),
            'custom_role_id' => $this->is_system ? null : $this->id,
        ]);
    }

    /**
     * Find the role of an AdminRole value.
     */
    public static function forAdminRole(AdminRole $role): self
    {
        return self::query()->where('slug', $role->value)->where('is_system', true)->firstOrFail();
    }
}
