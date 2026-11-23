<?php

namespace Tests\Feature\Finance;

use App\Enums\BankAccountType;
use App\Enums\BankCatalogType;
use App\Enums\RecurringKind;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\BankTransaction;
use App\Models\ExpenseOccurrence;
use App\Models\PersonalTransaction;
use App\Models\RecurringExpense;
use App\Services\OccurrenceGenerator;
use App\Services\PersonalSummary;
use App\Services\RecurringExpenseService;
use App\Support\Month;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\Concerns\FakesPluggy;
use Tests\TestCase;

class MoveExpenseToPersonalTest extends TestCase
{
    use CreatesHousehold, FakesPluggy, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-11-19 12:00:00');
        $this->configurePluggy();
        $this->createHousehold();
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_a_household_expense_becomes_an_expense_entry_of_who_moves_it(): void
    {
        $expense = $this->createExpense($this->bruno, 13_990);
        $expense->update(['notes' => 'Presente']);
        $this->assertNotSame([], $this->sharesOf($expense));

        $this->actingAs($this->bruno)
            ->post(route('expenses.move-to-personal', $expense))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSoftDeleted($expense);
        $this->assertSame($this->bruno->id, ExpenseOccurrence::withTrashed()->findOrFail($expense->id)->updated_by_user_id);

        $entry = PersonalTransaction::query()->sole();
        $this->assertSame($this->bruno->id, $entry->user_id);
        $this->assertSame(TransactionType::Expense, $entry->type);
        $this->assertSame($expense->title, $entry->title);
        $this->assertSame('Presente', $entry->notes);
        $this->assertSame(13_990, $entry->amount_cents);
        $this->assertSame($expense->due_date->toDateString(), $entry->transaction_date->toDateString());

        $audit = AuditLog::query()->where('action', 'expense.moved_to_personal')->sole();
        $this->assertSame($this->bruno->id, $audit->user_id);
        $this->assertSame($expense->id, $audit->auditable_id);
        $this->assertSame('household', $audit->old_values['scope']);
        $this->assertSame($entry->id, $audit->new_values['personal_transaction_id']);

        // It now counts in full for Bruno and no longer for Letícia.
        $month = Month::current();
        $bruno = app(PersonalSummary::class)->month($this->bruno, $month);
        $this->assertSame(13_990, $bruno['ledger_expense_cents']);
        $this->assertSame(0, $bruno['household_share_cents']);
        $this->assertSame(0, app(PersonalSummary::class)->month($this->leticia, $month)['household_share_cents']);

        $this->actingAs($this->bruno)
            ->get(route('personal.transactions.index', ['type' => 'expenses', 'month' => Month::key($month)]))
            ->assertInertia(fn (Assert $page) => $page->where('transactions.data.0.id', $entry->id));
        $this->actingAs($this->leticia)
            ->get(route('expenses.index'))
            ->assertInertia(fn (Assert $page) => $page->has('occurrences.data', 0));
        $this->actingAs($this->leticia)->delete(route('personal.transactions.destroy', $entry))->assertNotFound();
    }

    public function test_any_member_can_take_a_household_expense_but_only_into_their_own_finances(): void
    {
        $expense = $this->createExpense($this->bruno, 10_000);

        $this->actingAs($this->leticia)->post(route('expenses.move-to-personal', $expense))->assertSessionHasNoErrors();

        $this->assertSame($this->leticia->id, PersonalTransaction::query()->sole()->user_id);
        $this->actingAs($this->bruno)->post(route('expenses.move-to-personal', $expense))->assertNotFound();
        $this->assertSame(1, PersonalTransaction::query()->count());
    }

    public function test_only_pending_expenses_of_the_household_with_an_amount_can_be_moved(): void
    {
        $paid = $this->createExpense($this->bruno, 10_000);
        $this->actingAs($this->leticia)->post(route('expenses.payment.store', $paid));
        $this->actingAs($this->bruno)->post(route('expenses.move-to-personal', $paid))->assertForbidden();
        $this->assertNotSoftDeleted($paid);

        $pending = $this->createExpense($this->bruno, 7_000);
        $this->actingAs($this->createOutsider())->post(route('expenses.move-to-personal', $pending))->assertNotFound();
        $this->assertNotSoftDeleted($pending);
        $this->assertSame(0, PersonalTransaction::query()->count());

        $this->actingAs($this->bruno)
            ->get(route('expenses.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('occurrences.data.0.id', $paid->id)
                ->where('occurrences.data.0.can.move_to_personal', false)
                ->where('occurrences.data.1.id', $pending->id)
                ->where('occurrences.data.1.can.move_to_personal', true));
    }

    public function test_only_this_month_of_a_recurring_expense_moves_and_the_series_stays_in_the_household(): void
    {
        $template = $this->createTemplate();
        $month = $template->occurrences()->forMonth(Month::parse('2026-11'))->sole();

        $this->actingAs($this->bruno)->post(route('expenses.move-to-personal', $month))->assertSessionHasNoErrors();

        $this->assertSoftDeleted($month);

        $entry = PersonalTransaction::query()->sole();
        $this->assertSame($this->bruno->id, $entry->user_id);
        $this->assertSame('Internet', $entry->title);
        $this->assertSame(12_000, $entry->amount_cents);
        $this->assertSame('2026-11-10', $entry->transaction_date->toDateString());

        // The month is not generated again, the others stay in the household
        // and template edits leave the entry alone.
        app(OccurrenceGenerator::class)->generate($template->fresh());
        $this->assertSame(0, $template->occurrences()->forMonth(Month::parse('2026-11'))->count());
        $this->assertSame(1, $template->occurrences()->forMonth(Month::parse('2026-12'))->count());

        $this->actingAs($this->bruno)
            ->put(route('recurring-expenses.update', $template), [
                'title' => 'Internet fibra', 'amount' => '150.00', 'due_day' => '10', 'effective_month' => '2026-11',
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame(['Internet', 12_000], [$entry->fresh()->title, $entry->fresh()->amount_cents]);

        $this->actingAs($this->leticia)->delete(route('recurring-expenses.destroy', $template))->assertSessionHasNoErrors();
        $this->assertModelExists($entry);
    }

    public function test_a_variable_month_needs_its_amount_before_moving(): void
    {
        $template = $this->createTemplate(['title' => 'Luz', 'amount_cents' => 0, 'is_variable' => true]);
        $month = $template->occurrences()->forMonth(Month::parse('2026-11'))->sole();

        $this->actingAs($this->bruno)
            ->get(route('expenses.index'))
            ->assertInertia(fn (Assert $page) => $page->where('occurrences.data.0.can.move_to_personal', false));
        $this->actingAs($this->leticia)->post(route('expenses.move-to-personal', $month))->assertForbidden();
        $this->assertNotSoftDeleted($month);
        $this->assertSame(0, PersonalTransaction::query()->count());
    }

    public function test_an_expense_from_a_members_bank_moves_only_to_that_member_and_keeps_its_transaction(): void
    {
        $account = $this->createBankAccount($this->createBankConnection($this->bruno), BankAccountType::Credit);
        $transaction = $this->createBankTransaction($account, ['description' => 'Smartwatch', 'amount' => 139.9]);

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $transaction), ['action' => 'household', 'title' => 'Smartwatch Mãe', 'remember' => '0'])
            ->assertSessionHasNoErrors();
        $expense = ExpenseOccurrence::query()->sole();

        $this->actingAs($this->leticia)
            ->get(route('expenses.index', ['month' => Month::key($expense->billing_month)]))
            ->assertInertia(fn (Assert $page) => $page->where('occurrences.data.0.can.move_to_personal', false));
        $this->actingAs($this->leticia)->post(route('expenses.move-to-personal', $expense))->assertForbidden();

        $this->actingAs($this->bruno)->post(route('expenses.move-to-personal', $expense))->assertSessionHasNoErrors();

        $this->assertSoftDeleted($expense);
        $entry = PersonalTransaction::query()->sole();
        $this->assertSame('Smartwatch Mãe', $entry->title);
        $transaction->refresh();
        $this->assertNull($transaction->expense_occurrence_id);
        $this->assertSame($entry->id, $transaction->personal_transaction_id);
        $this->assertSame(BankCatalogType::Personal, $transaction->catalog_type);

        $this->actingAs($this->bruno)
            ->get(route('bank.inbox', ['status' => 'catalogued']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('transactions.data.0.catalog_label', 'Despesa pessoal')
                ->where('transactions.data.0.entry.id', $entry->id));

        // Undoing in the inbox removes the entry.
        $this->actingAs($this->bruno)->delete(route('bank.transactions.reopen', $transaction))->assertSessionHasNoErrors();
        $this->assertModelMissing($entry);
        $this->assertTrue($transaction->fresh()->isPending());
    }

    public function test_a_recurring_month_linked_to_the_bank_takes_the_transaction_along(): void
    {
        $template = $this->createTemplate();
        $month = $template->occurrences()->forMonth(Month::parse('2026-11'))->sole();
        $account = $this->createBankAccount($this->createBankConnection($this->bruno));
        $transaction = $this->createBankTransaction($account, ['description' => 'Internet', 'amount' => 120]);

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $transaction), ['action' => 'link', 'occurrence_id' => $month->id, 'remember' => '0'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->bruno)->post(route('expenses.move-to-personal', $month))->assertSessionHasNoErrors();

        $entry = PersonalTransaction::query()->sole();
        $transaction->refresh();
        $this->assertSame($entry->id, $transaction->personal_transaction_id);
        $this->assertSame(BankCatalogType::Personal, $transaction->catalog_type);
        $this->assertSame(0, BankTransaction::query()->where('expense_occurrence_id', $month->id)->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTemplate(array $overrides = []): RecurringExpense
    {
        return app(RecurringExpenseService::class)->create($this->household, $this->bruno, [
            'kind' => RecurringKind::Fixed,
            'title' => 'Internet',
            'notes' => null,
            'amount_cents' => 12_000,
            'due_day' => 10,
            'start_month' => Month::parse('2026-10'),
            'end_month' => null,
            'total_months' => null,
            ...$overrides,
        ]);
    }
}
