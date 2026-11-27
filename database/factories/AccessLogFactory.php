<?php

namespace Database\Factories;

use App\Enums\AccessResult;
use App\Models\AccessLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessLog>
 */
class AccessLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'access_token_id' => null,
            'project_id' => null,
            'requested_slug' => null,
            'ip' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'result' => AccessResult::Ok,
        ];
    }

    /**
     * Log a request whose token was not recognised, so no token or project is known.
     */
    public function unknownToken(): static
    {
        return $this->state(['result' => AccessResult::UnknownToken]);
    }
}
