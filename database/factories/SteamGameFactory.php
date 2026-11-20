<?php

namespace Database\Factories;

use App\Models\SteamAccount;
use App\Models\SteamGame;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SteamGame>
 */
class SteamGameFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'steam_account_id' => SteamAccount::factory(),
            'app_id' => fake()->unique()->numberBetween(10, 3_000_000),
            'name' => fake()->words(2, true),
            'playtime_minutes' => 120,
            'added_at' => now(),
        ];
    }
}
