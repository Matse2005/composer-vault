<?php

namespace Database\Factories;

use App\Models\AccessToken;
use App\Models\Project;
use App\Vault\Tokens\TokenIssuer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use LogicException;

/**
 * @extends Factory<AccessToken>
 */
class AccessTokenFactory extends Factory
{
    /**
     * Plain tokens of factory tokens built with withPlainToken(), by token prefix. For tests only.
     *
     * @var array<string, string>
     */
    private static array $plainTokens = [];

    /**
     * Define the model's default state.
     *
     * The default hash belongs to a random string, so such a token cannot authenticate. Use withPlainToken()
     * for a token that can.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->unique()->slug(2),
            'token_prefix' => Str::random(8),
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
     * Give the token a real plain token, readable with plainTokenFor(). For tests only.
     */
    public function withPlainToken(): static
    {
        return $this->state(function (): array {
            $plainToken = app(TokenIssuer::class)->generatePlainToken();
            self::$plainTokens[TokenIssuer::prefix($plainToken)] = $plainToken;

            return [
                'token_prefix' => TokenIssuer::prefix($plainToken),
                'token_hash' => TokenIssuer::hash($plainToken),
            ];
        });
    }

    /**
     * Get the plain token of a token that was built with withPlainToken(). For tests only.
     */
    public static function plainTokenFor(AccessToken $token): string
    {
        return self::$plainTokens[$token->token_prefix]
            ?? throw new LogicException('This token was not built with withPlainToken().');
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
