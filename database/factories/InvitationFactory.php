<?php

namespace Database\Factories;

use App\Models\Invitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'token_hash' => hash('sha256', Str::random(48)),
            'invited_by' => null,
            'expires_at' => now()->addHours(48),
            'accepted_at' => null,
            'revoked_at' => null,
        ];
    }

    /**
     * Mark the invitation as expired.
     */
    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }

    /**
     * Mark the invitation as accepted.
     */
    public function accepted(): static
    {
        return $this->state(['accepted_at' => now()->subMinute()]);
    }

    /**
     * Mark the invitation as revoked.
     */
    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()->subMinute()]);
    }
}
