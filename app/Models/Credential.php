<?php

namespace App\Models;

use App\Enums\CredentialType;
use App\Vault\HostName;
use Database\Factories\CredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property CredentialType $type
 * @property string $host
 * @property string|null $username
 * @property string $secret_ciphertext
 * @property string $data_key_ciphertext
 * @property int $key_version
 * @property Carbon $secret_changed_at
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'type', 'host', 'username', 'notes'])]
#[Hidden(['secret_ciphertext', 'data_key_ciphertext', 'key_version'])]
class Credential extends Model
{
    /** @use HasFactory<CredentialFactory> */
    use HasFactory, HasUlids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CredentialType::class,
            'secret_changed_at' => 'datetime',
        ];
    }

    /**
     * Normalize the host to a lowercase hostname when it is assigned.
     *
     * @return Attribute<string, string>
     */
    protected function host(): Attribute
    {
        return Attribute::set(fn (string $value): string => HostName::normalize($value));
    }

    /**
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class);
    }
}
