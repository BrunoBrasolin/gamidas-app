<?php

namespace Tests\Concerns;

use App\Enums\ExpenseScope;
use App\Models\ExpenseCategory;
use App\Models\ExpenseOccurrence;
use App\Models\Household;
use App\Models\User;
use App\Services\ExpenseService;
use App\Services\HouseholdService;
use App\Support\Month;
use Carbon\CarbonImmutable;

trait CreatesHousehold
{
    protected Household $household;

    protected User $bruno;

    protected User $leticia;

    /**
     * Create the reference household: Bruno earns R$ 8.000,00 and Letícia
     * R$ 2.000,00, so household expenses split 80/20.
     */
    protected function createHousehold(int $brunoSalary = 800_000, int $leticiaSalary = 200_000): void
    {
        $service = app(HouseholdService::class);

        $this->household = $service->create('Casa Bruno & Letícia');
        $this->bruno = $service->addMember($this->household, 'Bruno Brasolin', 'bruno@example.test', 'password', 'pelo', $brunoSalary)->user;
        $this->leticia = $service->addMember($this->household, 'Letícia Souza', 'leticia@example.test', 'password', 'pela', $leticiaSalary)->user;
    }

    /**
     * A user who belongs to a different household.
     */
    protected function createOutsider(): User
    {
        $service = app(HouseholdService::class);
        $other = $service->create('Outra casa');

        return $service->addMember($other, 'Carlos Outro', 'carlos@example.test', 'password', 'pelo', 300_000)->user;
    }

    protected function category(string $name = 'Internet'): ExpenseCategory
    {
        return $this->household->categories()->where('name', $name)->firstOrFail();
    }

    protected function createExpense(User $creator, int $amountCents, ?CarbonImmutable $month = null, ExpenseScope $scope = ExpenseScope::Household): ExpenseOccurrence
    {
        $month ??= Month::current();

        return app(ExpenseService::class)->create($this->household, $creator, [
            'scope' => $scope,
            'title' => 'Conta de luz',
            'notes' => null,
            'expense_category_id' => $this->category('Luz')->id,
            'amount_cents' => $amountCents,
            'billing_month' => $month,
            'due_date' => $month->addDays(9),
        ]);
    }

    /**
     * Shares of an occurrence keyed by user id.
     *
     * @return array<int, int>
     */
    protected function sharesOf(ExpenseOccurrence $occurrence): array
    {
        return $occurrence->shares()->pluck('amount_cents', 'user_id')->map(fn ($cents) => (int) $cents)->all();
    }
}
