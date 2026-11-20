<?php

namespace Database\Factories;

use App\Models\SteamAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SteamAccount>
 */
class SteamAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'steam_id' => '7656119'.fake()->unique()->numerify('##########'),
            'persona_name' => fake()->userName(),
            'public_profile' => true,
            'public_games' => true,
            'persona_state' => 0,
            'level' => 12,
            'checked_at' => now(),
            'synced_at' => now(),
        ];
    }
}
