<?php

namespace Database\Factories;

use App\Enums\ExpenseScope;
use App\Enums\RecurringKind;
use App\Models\Household;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Support\Month;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates bare templates without occurrences; use RecurringExpenseService to
 * create templates together with their generated months.
 *
 * @extends Factory<RecurringExpense>
 */
class RecurringExpenseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'scope' => ExpenseScope::Household,
            'kind' => RecurringKind::Fixed,
            'title' => 'Internet',
            'amount_cents' => 10_000,
            'due_day' => 10,
            'start_month' => Month::current(),
            'created_by_user_id' => User::factory(),
        ];
    }
}
