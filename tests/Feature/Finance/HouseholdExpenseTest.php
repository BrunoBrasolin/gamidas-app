<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\ExpenseCategory;
use App\Models\ExpenseOccurrence;
use App\Support\Month;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class HouseholdExpenseTest extends TestCase
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

    public function test_a_household_expense_is_created_with_its_creator_and_shares(): void
    {
        $this->actingAs($this->leticia)
            ->post(route('expenses.store'), [
                'scope' => 'household',
                'title' => 'Mercado do mês',
                'expense_category_id' => $this->category('Mercado')->id,
                'amount' => '1234.56',
                'billing_month' => '2026-10',
                'due_date' => '2026-10-30',
                'notes' => 'Compra grande',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $expense = ExpenseOccurrence::query()->sole();
        $this->assertSame(123_456, $expense->amount_cents);
        $this->assertSame($this->leticia->id, $expense->created_by_user_id);
        $this->assertNotNull($expense->created_at);
        $this->assertNull($expense->owner_user_id);
        $this->assertSame([$this->bruno->id => 98_765, $this->leticia->id => 24_691], $this->sharesOf($expense));
        $this->assertSame(123_456, array_sum($this->sharesOf($expense)));
        $this->assertDatabaseHas('audit_logs', ['action' => 'expense.created', 'user_id' => $this->leticia->id]);
    }

    public function test_editing_records_the_last_editor_and_recalculates_shares(): void
    {
        $expense = $this->createExpense($this->leticia, 10_000);

        $this->actingAs($this->bruno)
            ->put(route('expenses.update', $expense), [
                'title' => 'Conta de luz (corrigida)',
                'amount' => '150.00',
                'billing_month' => '2026-10',
                'due_date' => '2026-10-15',
            ])
            ->assertSessionHasNoErrors();

        $expense->refresh();
        $this->assertSame(15_000, $expense->amount_cents);
        $this->assertSame($this->bruno->id, $expense->updated_by_user_id);
        $this->assertSame($this->leticia->id, $expense->created_by_user_id);
        $this->assertSame([$this->bruno->id => 12_000, $this->leticia->id => 3_000], $this->sharesOf($expense));
    }

    public function test_deleting_a_pending_expense_is_soft_and_audited(): void
    {
        $expense = $this->createExpense($this->leticia, 10_000);

        $this->actingAs($this->bruno)->delete(route('expenses.destroy', $expense))->assertSessionHasNoErrors();

        $this->assertSoftDeleted($expense);
        $this->assertSame($this->bruno->id, AuditLog::query()->where('action', 'expense.deleted')->sole()->user_id);
    }

    public function test_personal_expenses_belong_to_their_creator_and_are_hidden_from_others(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('expenses.store'), [
                'scope' => 'personal',
                'title' => 'Academia',
                'amount' => '99.90',
                'billing_month' => '2026-10',
                'due_date' => '2026-10-10',
            ])
            ->assertSessionHasNoErrors();

        $expense = ExpenseOccurrence::query()->sole();
        $this->assertSame($this->bruno->id, $expense->owner_user_id);
        $this->assertSame([], $this->sharesOf($expense));

        $this->actingAs($this->leticia)
            ->get(route('expenses.index', ['scope' => 'personal']))
            ->assertInertia(fn (Assert $page) => $page->has('occurrences.data', 0));
        $this->actingAs($this->leticia)
            ->get(route('expenses.index'))
            ->assertInertia(fn (Assert $page) => $page->has('occurrences.data', 0));
        $this->actingAs($this->bruno)
            ->get(route('expenses.index', ['scope' => 'personal']))
            ->assertInertia(fn (Assert $page) => $page->has('occurrences.data', 1));

        $this->actingAs($this->leticia)->put(route('expenses.update', $expense), ['title' => 'x'])->assertNotFound();
    }

    public function test_the_monthly_table_filters_and_totals(): void
    {
        $this->createExpense($this->leticia, 10_000);
        $paid = $this->createExpense($this->bruno, 20_000);
        $this->actingAs($this->leticia)->post(route('expenses.payment.store', $paid));
        $this->createExpense($this->bruno, 99_999, Month::parse('2026-11'));

        $this->actingAs($this->bruno)
            ->get(route('expenses.index', ['month' => '2026-10']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('expenses/index')
                ->has('occurrences.data', 2)
                ->where('totals.total_cents', 30_000)
                ->where('totals.paid_cents', 20_000)
                ->where('totals.pending_cents', 10_000)
                ->where('totals.members.'.$this->bruno->id, 24_000)
                ->where('totals.members.'.$this->leticia->id, 6_000)
            );

        $this->actingAs($this->bruno)
            ->get(route('expenses.index', ['month' => '2026-10', 'status' => 'paid', 'creator' => $this->bruno->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('occurrences.data', 1)
                ->where('occurrences.data.0.payment.paid_label', 'Pago pela Letícia')
                ->where('totals.total_cents', 20_000)
            );
    }

    public function test_expense_validation_edge_cases(): void
    {
        $foreignCategory = ExpenseCategory::factory()->create();

        $this->actingAs($this->bruno)
            ->post(route('expenses.store'), [
                'scope' => 'everyone',
                'title' => str_repeat('x', 121),
                'expense_category_id' => $foreignCategory->id,
                'amount' => '0.00',
                'billing_month' => '2026-13',
                'due_date' => '2026-02-29',
            ])
            ->assertSessionHasErrors(['scope', 'title', 'expense_category_id', 'amount', 'billing_month', 'due_date']);

        $this->actingAs($this->bruno)
            ->post(route('expenses.store'), [
                'scope' => 'household', 'title' => 'Teto', 'amount' => '10000000.00', 'billing_month' => '2026-10', 'due_date' => '2026-10-10',
            ])
            ->assertSessionHasErrors('amount');

        $this->actingAs($this->bruno)
            ->post(route('expenses.store'), [
                'scope' => 'household', 'title' => 'Um centavo', 'amount' => '0.01', 'billing_month' => '2026-10', 'due_date' => '2026-10-31',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ExpenseOccurrence::query()->count());
        $this->assertSame(1, array_sum($this->sharesOf(ExpenseOccurrence::query()->sole())));
    }
}
