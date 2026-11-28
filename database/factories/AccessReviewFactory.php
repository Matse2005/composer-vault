<?php

namespace Database\Factories;

use App\Models\AccessReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessReview>
 */
class AccessReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'completed_by' => null,
            'completed_at' => now(),
            'notes' => null,
            'snapshot' => ['admins' => 0, 'tokens' => 0, 'credentials' => 0],
        ];
    }
}
