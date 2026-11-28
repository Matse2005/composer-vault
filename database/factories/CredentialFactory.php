<?php

namespace Database\Factories;

use App\Enums\CredentialType;
use App\Models\Credential;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Credential>
 */
class CredentialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->words(2, true),
            'type' => CredentialType::HttpBasic,
            'host' => fake()->unique()->domainName(),
            'username' => fake()->userName(),
            'notes' => null,
        ];
    }

    /**
     * Encrypt a secret with the vault cipher after making the model.
     *
     * The secret travels as a temporary "secret" attribute from the state to this callback, which removes it
     * again, so it is never stored.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Credential $credential): void {
            $secret = $credential->getAttributes()['secret'] ?? null;
            $credential->offsetUnset('secret');

            $credential->setSecret(is_string($secret) ? $secret : Str::random(32));
        });
    }

    /**
     * Use a specific secret instead of a random one.
     */
    public function withSecret(string $secret): static
    {
        return $this->state(['secret' => $secret]);
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
