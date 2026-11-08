<?php

namespace Database\Factories;

use App\Enums\RouletteCategory;
use App\Models\Household;
use App\Models\RoulettePlace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoulettePlace>
 */
class RoulettePlaceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'category' => RouletteCategory::Lunch,
            'name' => fake()->unique()->words(2, true),
        ];
    }
}
