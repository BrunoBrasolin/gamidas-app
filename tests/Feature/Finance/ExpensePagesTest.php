<?php

namespace Tests\Feature\Finance;

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
 * the former personal bills were merged into Finanças pessoais › Despesas.
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

    private function template(User $user, string $title): RecurringExpense
    {
        return app(RecurringExpenseService::class)->create($this->household, $user, [
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

    public function test_the_expenses_page_lists_the_household(): void
    {
        $this->actingAs($this->bruno)
            ->get(route('expenses.index', ['month' => '2026-10']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('expenses/index')
                ->where('filters.month', '2026-10')
                ->missing('filters.scope'));
    }

    public function test_old_links_to_personal_bills_land_on_the_personal_expenses(): void
    {
        $expenses = route('personal.transactions.index', 'expenses');

        $this->actingAs($this->bruno)->get('/expenses?scope=personal&month=2026-10&status=paid')->assertRedirect($expenses);
        $this->actingAs($this->bruno)->get('/personal/bills')->assertRedirect('/personal/expenses');
        $this->actingAs($this->bruno)->get('/personal/bills/recurring')->assertRedirect('/personal/expenses');
    }

    public function test_the_recurring_tab_lists_the_household_templates(): void
    {
        $internet = $this->template($this->bruno, 'Internet');
        $gym = $this->template($this->leticia, 'Academia');

        $this->actingAs($this->bruno)
            ->get(route('expenses.recurring'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('recurring-expenses/index')
                ->has('templates.data', 2)
                ->where('templates.data.0.id', $gym->id)
                ->where('templates.data.1.id', $internet->id));
    }

    public function test_the_old_recurring_page_redirects_to_the_household_tab(): void
    {
        $this->actingAs($this->bruno)
            ->get('/recurring-expenses')
            ->assertRedirect('/expenses/recurring');
    }
}
