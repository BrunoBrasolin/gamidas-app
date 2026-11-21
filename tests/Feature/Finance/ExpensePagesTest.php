<?php

namespace Tests\Feature\Finance;

use App\Enums\ExpenseScope;
use App\Enums\RecurringKind;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Services\RecurringExpenseService;
use App\Support\Month;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

/**
 * Despesas da casa holds only the household (with a "Recorrentes" tab);
 * personal bills and their recurring ones live in Finanças pessoais.
 */
class ExpensePagesTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-10-21 10:00:00');
        $this->createHousehold();
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    private function template(User $user, ExpenseScope $scope, string $title): RecurringExpense
    {
        return app(RecurringExpenseService::class)->create($this->household, $user, [
            'scope' => $scope,
            'kind' => RecurringKind::Fixed,
            'title' => $title,
            'notes' => null,
            'amount_cents' => 5_000,
            'due_day' => 10,
            'start_month' => Month::parse('2026-10'),
            'end_month' => null,
            'total_months' => null,
        ]);
    }

    public function test_personal_bills_are_a_page_of_personal_finances(): void
    {
        $this->actingAs($this->bruno)
            ->get(route('personal.bills', ['month' => '2026-10']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('personal/bills')
                ->where('filters.scope', 'personal')
                ->where('filters.month', '2026-10'));

        $this->actingAs($this->bruno)
            ->get(route('expenses.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('expenses/index')
                ->where('filters.scope', 'household'));
    }

    public function test_old_links_to_personal_expenses_land_on_the_personal_bills(): void
    {
        $this->actingAs($this->bruno)
            ->get('/expenses?scope=personal&month=2026-10&status=paid')
            ->assertRedirect(route('personal.bills', ['month' => '2026-10', 'status' => 'paid']));
    }

    public function test_recurring_expenses_are_split_by_scope(): void
    {
        $internet = $this->template($this->bruno, ExpenseScope::Household, 'Internet');
        $gym = $this->template($this->bruno, ExpenseScope::Personal, 'Academia');
        $this->template($this->leticia, ExpenseScope::Personal, 'Curso da Letícia');

        $this->actingAs($this->bruno)
            ->get(route('expenses.recurring'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('recurring-expenses/index')
                ->where('scope', 'household')
                ->has('templates.data', 1)
                ->where('templates.data.0.id', $internet->id));

        $this->actingAs($this->bruno)
            ->get(route('personal.bills.recurring'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('personal/recurring')
                ->where('scope', 'personal')
                ->has('templates.data', 1)
                ->where('templates.data.0.id', $gym->id));
    }

    public function test_the_old_recurring_page_redirects_to_the_household_tab(): void
    {
        $this->actingAs($this->bruno)
            ->get('/recurring-expenses')
            ->assertRedirect('/expenses/recurring');
    }
}
