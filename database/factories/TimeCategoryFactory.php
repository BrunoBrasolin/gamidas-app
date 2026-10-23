<?php

namespace Database\Factories;

use App\Models\TimeCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * @extends Factory<TimeCategory>
 */
class TimeCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(2, true),
        ];
    }

    public function archived(): static
    {
        return $this->state(['archived_at' => Date::now()]);
    }
}
