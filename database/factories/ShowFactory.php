<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Show;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Show>
 */
class ShowFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'band' => fake()->words(2, true),
            'tour' => fake()->words(3, true),
            'venue' => fake()->city(),
            'show_date' => '2026-01-31',
            'setlist' => ['Abertura', 'Hit', 'Bis'],
        ];
    }
}
