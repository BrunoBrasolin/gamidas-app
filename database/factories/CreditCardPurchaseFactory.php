<?php

namespace Database\Factories;

use App\Enums\ExpenseScope;
use App\Enums\PurchaseMode;
use App\Models\CreditCard;
use App\Models\CreditCardPurchase;
use App\Models\Household;
use App\Models\User;
use App\Support\Month;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * Creates bare purchases without installments; use CreditCardPurchaseService
 * to create purchases together with their installments.
 *
 * @extends Factory<CreditCardPurchase>
 */
class CreditCardPurchaseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'credit_card_id' => CreditCard::factory(),
            'scope' => ExpenseScope::Household,
            'description' => fake()->words(3, true),
            'purchase_date' => Date::today(),
            'mode' => PurchaseMode::Installments,
            'total_amount_cents' => 120_000,
            'installment_count' => 6,
            'first_billing_month' => Month::current(),
            'created_by_user_id' => User::factory(),
        ];
    }
}
