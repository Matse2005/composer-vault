<?php

namespace Database\Factories;

use App\Models\Incident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'detected_at' => now()->subHour(),
            'closed_at' => null,
            'notes' => null,
            'created_by' => null,
        ];
    }

    /**
     * Mark the incident as closed.
     */
    public function closed(): static
    {
        return $this->state(['closed_at' => now()]);
    }
}
