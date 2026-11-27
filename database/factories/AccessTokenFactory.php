<?php

namespace Database\Factories;

use App\Models\AccessToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AccessToken>
 */
class AccessTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * The hash belongs to a random string, so the factory token cannot be used to authenticate until phase 2
     * adds a helper that returns a usable plain token.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->slug(2),
            'token_prefix' => 'cv_'.Str::random(5),
            'token_hash' => hash('sha256', Str::random(40)),
            'allowed_ips' => null,
            'expires_at' => null,
            'revoked_at' => null,
            'last_used_at' => null,
            'last_used_ip' => null,
            'created_by' => null,
        ];
    }

    /**
     * Mark the token as revoked.
     */
    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()->subMinute()]);
    }

    /**
     * Mark the token as expired.
     */
    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }

    /**
     * Restrict the token to a list of IP addresses and CIDR ranges.
     *
     * @param  array<int, string>  $ips
     */
    public function withAllowedIps(array $ips): static
    {
        return $this->state(['allowed_ips' => $ips]);
    }
}
