<?php

namespace App\Models;

use App\Enums\CredentialType;
use App\Exceptions\Vault\DecryptionFailedException;
use App\Vault\Crypto\EncryptedSecret;
use App\Vault\Crypto\SecretCipher;
use App\Vault\HostName;
use Database\Factories\CredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use SensitiveParameter;

/**
 * @property string $id
 * @property string $project_id
 * @property-read Project $project
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
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Encrypt a secret into the crypto columns. Does not save the model.
     *
     * The ID is generated first because it is bound into the ciphertext as associated data.
     */
    public function setSecret(#[SensitiveParameter] string $plaintext): void
    {
        $this->{$this->getKeyName()} ??= $this->newUniqueId();

        $encrypted = app(SecretCipher::class)->encrypt((string) $this->getKey(), $plaintext);

        $this->setAttribute('secret_ciphertext', $encrypted->secretCiphertext);
        $this->setAttribute('data_key_ciphertext', $encrypted->dataKeyCiphertext);
        $this->setAttribute('key_version', $encrypted->keyVersion);
        $this->setAttribute('secret_changed_at', now());
    }

    /**
     * Decrypt the secret. Only the API response builder calls this.
     *
     * @throws DecryptionFailedException
     */
    public function revealSecret(): string
    {
        return app(SecretCipher::class)->decrypt((string) $this->getKey(), $this->encryptedSecret());
    }

    /**
     * Get the stored ciphertexts and key version as one value.
     */
    public function encryptedSecret(): EncryptedSecret
    {
        return new EncryptedSecret(
            secretCiphertext: (string) $this->getAttribute('secret_ciphertext'),
            dataKeyCiphertext: (string) $this->getAttribute('data_key_ciphertext'),
            keyVersion: (int) $this->getAttribute('key_version'),
        );
    }

    /**
     * Show only the attributes that are visible in arrays, so dumps never contain the ciphertext.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return $this->attributesToArray();
    }
}
