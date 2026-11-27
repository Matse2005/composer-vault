<?php

namespace Database\Factories;

use App\Enums\CredentialType;
use App\Models\Credential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Credential>
 */
class CredentialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The crypto columns hold placeholders until the secret cipher exists in phase 2.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'type' => CredentialType::HttpBasic,
            'host' => fake()->unique()->domainName(),
            'username' => fake()->userName(),
            'secret_ciphertext' => 'placeholder-secret-ciphertext',
            'data_key_ciphertext' => 'placeholder-data-key-ciphertext',
            'key_version' => 1,
            'secret_changed_at' => now(),
            'notes' => null,
        ];
    }

    /**
     * Use a specific host.
     */
    public function forHost(string $host): static
    {
        return $this->state(['host' => $host]);
    }

    /**
     * Use a specific type, with a username only when the type needs one.
     */
    public function ofType(CredentialType $type): static
    {
        return $this->state([
            'type' => $type,
            'username' => $type->requiresUsername() ? fake()->userName() : null,
        ]);
    }
}
