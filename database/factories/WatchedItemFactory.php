<?php

namespace Database\Factories;

use App\Enums\WatchType;
use App\Models\Household;
use App\Models\WatchedItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WatchedItem>
 */
class WatchedItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'viewer' => 'bruno',
            'type' => WatchType::Movie,
            'title' => fake()->words(3, true),
            'year' => 2024,
            'runtime_seconds' => 7_200,
            'jellyfin_item_id' => fake()->uuid(),
            'watched_at' => '2026-10-20 21:30:00',
            'watched_on' => '2026-10-20',
        ];
    }

    public function episode(string $series = 'Série', int $season = 1, int $episode = 1): static
    {
        return $this->state([
            'type' => WatchType::Episode,
            'series_name' => $series,
            'season_number' => $season,
            'episode_number' => $episode,
            'runtime_seconds' => 2_700,
        ]);
    }
}
