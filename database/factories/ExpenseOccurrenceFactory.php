<?php

namespace Database\Factories;

use App\Enums\ExpenseScope;
use App\Enums\OccurrenceSource;
use App\Enums\OccurrenceStatus;
use App\Models\ExpenseOccurrence;
use App\Models\Household;
use App\Models\User;
use App\Support\Month;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates occurrences without shares; use ExpenseService to create expenses
 * with their proportional shares.
 *
 * @extends Factory<ExpenseOccurrence>
 */
class ExpenseOccurrenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'scope' => ExpenseScope::Household,
            'source' => OccurrenceSource::OneOff,
            'title' => fake()->words(2, true),
            'billing_month' => Month::current(),
            'due_date' => Month::current()->addDays(9),
            'amount_cents' => 10_000,
            'status' => OccurrenceStatus::Pending,
            'created_by_user_id' => User::factory(),
        ];
    }
}
