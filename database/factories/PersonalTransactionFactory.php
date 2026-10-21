<?php

namespace Database\Factories;

use App\Enums\TransactionType;
use App\Models\PersonalTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * @extends Factory<PersonalTransaction>
 */
class PersonalTransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => TransactionType::Expense,
            'category' => 'Outras despesas',
            'description' => fake()->words(2, true),
            'amount_cents' => 5_000,
            'transaction_date' => Date::today(),
        ];
    }
}
