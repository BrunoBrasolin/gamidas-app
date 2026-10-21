<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
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
                'amount' => '1234.56',
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

    public function test_a_pending_expense_is_only_overdue_after_its_due_month_ends(): void
    {
        // Due on 10/10/2026.
        $this->createExpense($this->leticia, 10_000);

        Date::setTestNow('2026-10-31 23:59:59');

        $this->actingAs($this->bruno)
            ->get(route('expenses.index', ['month' => '2026-10']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('occurrences.data.0.is_overdue', false)
                ->where('summary.overdue_count', 0)
            );

        $this->actingAs($this->bruno)
            ->get(route('expenses.index', ['month' => '2026-10', 'status' => 'overdue']))
            ->assertInertia(fn (Assert $page) => $page->has('occurrences.data', 0));

        Date::setTestNow('2026-11-01 00:00:00');

        $this->actingAs($this->bruno)
            ->get(route('expenses.index', ['month' => '2026-10']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('occurrences.data.0.is_overdue', true)
                ->where('summary.overdue_count', 1)
            );

        $this->actingAs($this->bruno)
            ->get(route('expenses.index', ['month' => '2026-10', 'status' => 'overdue']))
            ->assertInertia(fn (Assert $page) => $page->has('occurrences.data', 1));
    }

    public function test_expense_validation_edge_cases(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('expenses.store'), [
                'scope' => 'everyone',
                'title' => str_repeat('x', 121),
                'amount' => '0.00',
                'installments' => '0',
                'due_date' => '2026-02-29',
            ])
            ->assertSessionHasErrors(['scope', 'title', 'amount', 'installments', 'due_date']);

        $this->actingAs($this->bruno)
            ->post(route('expenses.store'), [
                'scope' => 'household', 'title' => 'Muitas parcelas', 'amount' => '10.00', 'installments' => '121', 'due_date' => '2026-10-10',
            ])
            ->assertSessionHasErrors('installments');

        $this->actingAs($this->bruno)
            ->post(route('expenses.store'), [
                'scope' => 'household', 'title' => 'Teto', 'amount' => '10000000.00', 'due_date' => '2026-10-10',
            ])
            ->assertSessionHasErrors('amount');

        $this->actingAs($this->bruno)
            ->post(route('expenses.store'), [
                'scope' => 'household', 'title' => 'Um centavo', 'amount' => '0.01', 'due_date' => '2026-10-31',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ExpenseOccurrence::query()->count());
        $this->assertSame(1, array_sum($this->sharesOf(ExpenseOccurrence::query()->sole())));
    }

    public function test_the_billing_month_is_the_month_of_the_due_date(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('expenses.store'), [
                'scope' => 'household',
                'title' => 'IPTU',
                'amount' => '300.00',
                'billing_month' => '2026-01',
                'due_date' => '2026-12-02',
            ])
            ->assertSessionHasNoErrors();

        $expense = ExpenseOccurrence::query()->sole();
        $this->assertSame('2026-12', Month::key($expense->billing_month), 'A submitted billing month is ignored.');

        $this->actingAs($this->leticia)
            ->put(route('expenses.update', $expense), [
                'title' => 'IPTU',
                'amount' => '300.00',
                'due_date' => '2027-01-05',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('2027-01', Month::key($expense->fresh()->billing_month), 'Editing the due date moves the expense to its month.');
    }

    public function test_an_expense_in_installments_creates_one_occurrence_per_month(): void
    {
        $this->actingAs($this->leticia)
            ->post(route('expenses.store'), [
                'scope' => 'household',
                'title' => 'Geladeira',
                'amount' => '250.00',
                'installments' => '4',
                'due_date' => '2026-11-30',
            ])
            ->assertSessionHasNoErrors();

        $installments = ExpenseOccurrence::query()->orderBy('installment_number')->get();

        $this->assertCount(4, $installments);
        $this->assertSame(
            ['2026-11-30', '2026-12-30', '2027-01-30', '2027-02-28'],
            $installments->map(fn (ExpenseOccurrence $occurrence) => $occurrence->due_date->toDateString())->all(),
        );
        $this->assertSame(
            ['2026-11', '2026-12', '2027-01', '2027-02'],
            $installments->map(fn (ExpenseOccurrence $occurrence) => Month::key($occurrence->billing_month))->all(),
        );
        $this->assertSame([1, 2, 3, 4], $installments->pluck('installment_number')->all());
        $this->assertSame([4], $installments->pluck('installment_count')->unique()->values()->all());
        $this->assertSame([25_000], $installments->pluck('amount_cents')->unique()->values()->all());
        $this->assertSame([$this->leticia->id], $installments->pluck('created_by_user_id')->unique()->values()->all());
        $this->assertSame([$this->bruno->id => 20_000, $this->leticia->id => 5_000], $this->sharesOf($installments->last()));
        $this->assertSame(4, AuditLog::query()->where('action', 'expense.created')->count());

        // Each installment is paid, edited and removed on its own.
        $this->actingAs($this->bruno)->delete(route('expenses.destroy', $installments[3]))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($installments[3]);
        $this->assertSame(3, ExpenseOccurrence::query()->count());
    }

    public function test_a_single_installment_is_a_plain_expense(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('expenses.store'), [
                'scope' => 'household', 'title' => 'Mercado', 'amount' => '80.00', 'installments' => '1', 'due_date' => '2026-10-15',
            ])
            ->assertSessionHasNoErrors();

        $expense = ExpenseOccurrence::query()->sole();
        $this->assertNull($expense->installment_number);
        $this->assertNull($expense->installment_count);
    }
}
