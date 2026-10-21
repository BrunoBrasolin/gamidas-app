<?php

namespace Database\Factories;

use App\Models\CreditCard;
use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditCard>
 */
class CreditCardFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'name' => 'Cartão '.fake()->unique()->word(),
            'last_four_digits' => fake()->numerify('####'),
            'due_day' => 10,
            'is_active' => true,
            'created_by_user_id' => User::factory(),
        ];
    }
}
