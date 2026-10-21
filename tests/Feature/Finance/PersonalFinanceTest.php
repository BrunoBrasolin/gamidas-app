<?php

namespace Tests\Feature\Finance;

use App\Enums\ExpenseScope;
use App\Enums\TransactionType;
use App\Models\PersonalTransaction;
use App\Models\User;
use App\Services\PersonalSummary;
use App\Support\Month;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class PersonalFinanceTest extends TestCase
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

    private function transaction(User $user, TransactionType $type, int $cents, string $date, string $category = 'Outros'): PersonalTransaction
    {
        return PersonalTransaction::factory()->create([
            'user_id' => $user->id,
            'type' => $type,
            'amount_cents' => $cents,
            'transaction_date' => $date,
            'category' => $category,
        ]);
    }

    public function test_transactions_are_created_for_the_authenticated_user_only(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('personal.transactions.store', 'income'), [
                'description' => 'Salário outubro',
                'category' => 'Salário',
                'amount' => '8000.00',
                'transaction_date' => '2026-10-05',
                'user_id' => $this->leticia->id,
            ])
            ->assertSessionHasNoErrors();

        $transaction = PersonalTransaction::query()->sole();
        $this->assertSame($this->bruno->id, $transaction->user_id);
        $this->assertSame(TransactionType::Income, $transaction->type);
        $this->assertSame(800_000, $transaction->amount_cents);
    }

    public function test_personal_transactions_are_isolated_between_members(): void
    {
        $brunos = $this->transaction($this->bruno, TransactionType::Expense, 15_000, '2026-10-10');
        $this->transaction($this->leticia, TransactionType::Expense, 9_900, '2026-10-11');

        $page = $this->actingAs($this->leticia)->get(route('personal.transactions.index', 'expenses'))->assertOk();
        $this->assertSame([9_900], array_column($page->inertiaProps('transactions.data'), 'amount_cents'));
        $this->assertSame(9_900, $page->inertiaProps('total_cents'));

        $this->actingAs($this->leticia)
            ->put(route('personal.transactions.update', $brunos), [
                'description' => 'x', 'category' => 'x', 'amount' => '1.00', 'transaction_date' => '2026-10-10',
            ])
            ->assertNotFound();
        $this->actingAs($this->leticia)->delete(route('personal.transactions.destroy', $brunos))->assertNotFound();

        $this->assertSame(15_000, $brunos->fresh()->amount_cents);

        $summary = app(PersonalSummary::class)->month($this->leticia, Month::parse('2026-10'));
        $this->assertSame(9_900, $summary['ledger_expense_cents']);
    }

    public function test_owners_can_edit_and_delete_their_transactions(): void
    {
        $transaction = $this->transaction($this->bruno, TransactionType::Investment, 100_000, '2026-10-10');

        $this->actingAs($this->bruno)
            ->put(route('personal.transactions.update', $transaction), [
                'description' => 'Tesouro Selic', 'category' => 'Tesouro Direto', 'amount' => '1500.50', 'transaction_date' => '2026-10-12',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(150_050, $transaction->fresh()->amount_cents);
        $this->assertSame(TransactionType::Investment, $transaction->fresh()->type, 'The type cannot be changed by editing.');

        $this->actingAs($this->bruno)->delete(route('personal.transactions.destroy', $transaction))->assertSessionHasNoErrors();
        $this->assertModelMissing($transaction);
    }

    public function test_income_expenses_investments_and_balance_are_calculated_consistently(): void
    {
        $this->transaction($this->bruno, TransactionType::Income, 800_000, '2026-10-05');
        $this->transaction($this->bruno, TransactionType::Income, 50_000, '2026-10-20');
        $this->transaction($this->bruno, TransactionType::Expense, 12_000, '2026-10-08');
        $this->transaction($this->bruno, TransactionType::Investment, 100_000, '2026-10-15');
        $this->createExpense($this->leticia, 300_000, Month::parse('2026-10'));
        $this->createExpense($this->bruno, 9_900, Month::parse('2026-10'), ExpenseScope::Personal);
        $this->createExpense($this->leticia, 5_000, Month::parse('2026-10'), ExpenseScope::Personal);

        $summary = app(PersonalSummary::class)->month($this->bruno, Month::parse('2026-10'));

        $this->assertSame(850_000, $summary['income_cents']);
        $this->assertSame(12_000, $summary['ledger_expense_cents']);
        $this->assertSame(9_900, $summary['personal_bills_cents']);
        $this->assertSame(240_000, $summary['household_share_cents'], '80% of R$ 3.000,00');
        $this->assertSame(12_000 + 9_900 + 240_000, $summary['expense_cents']);
        $this->assertSame(100_000, $summary['investment_cents'], 'Investments are not expenses.');
        $this->assertSame(850_000 - 261_900 - 100_000, $summary['net_cents']);
        $this->assertSame($summary['net_cents'], $summary['balance_cents']);

        $leticia = app(PersonalSummary::class)->month($this->leticia, Month::parse('2026-10'));
        $this->assertSame(60_000, $leticia['household_share_cents']);
        $this->assertSame(5_000, $leticia['personal_bills_cents']);
    }

    public function test_the_available_balance_accumulates_across_months_and_years(): void
    {
        $this->transaction($this->bruno, TransactionType::Income, 100_000, '2025-12-31');
        $this->transaction($this->bruno, TransactionType::Expense, 30_000, '2026-01-01');
        $this->transaction($this->bruno, TransactionType::Income, 50_000, '2026-02-15');
        $this->transaction($this->bruno, TransactionType::Investment, 20_000, '2026-02-28');

        $summary = app(PersonalSummary::class);

        $this->assertSame(70_000, $summary->month($this->bruno, Month::parse('2026-01'))['balance_cents']);
        $this->assertSame(100_000, $summary->month($this->bruno, Month::parse('2026-02'))['balance_cents']);

        $year = $summary->year($this->bruno, 2026);
        $this->assertSame(100_000, $year['opening_balance_cents']);
        $this->assertSame(100_000, $year['closing_balance_cents']);
        $this->assertSame(50_000, $year['totals']['income_cents']);
        $this->assertSame(30_000, $year['totals']['expense_cents']);
        $this->assertSame(20_000, $year['totals']['investment_cents']);
        $this->assertSame(0, $year['totals']['net_cents']);
        $this->assertSame(-30_000, $year['months'][0]['net_cents']);
        $this->assertSame(70_000, $year['months'][0]['balance_cents']);
        $this->assertSame(100_000, $year['months'][11]['balance_cents']);
    }

    public function test_monthly_and_yearly_totals_agree(): void
    {
        $this->transaction($this->bruno, TransactionType::Income, 800_000, '2026-03-05');
        $this->transaction($this->bruno, TransactionType::Investment, 40_000, '2026-03-06');
        $this->transaction($this->bruno, TransactionType::Expense, 7_000, '2026-07-30');
        $this->createExpense($this->leticia, 50_000, Month::parse('2026-07'));

        $summary = app(PersonalSummary::class);
        $year = $summary->year($this->bruno, 2026);

        $monthly = array_map(fn (int $month) => $summary->month($this->bruno, Month::parse(sprintf('2026-%02d', $month))), range(1, 12));

        foreach (['income_cents', 'expense_cents', 'investment_cents', 'household_share_cents', 'net_cents'] as $field) {
            $this->assertSame(array_sum(array_column($monthly, $field)), $year['totals'][$field], $field);
        }

        $this->assertSame($monthly[11]['balance_cents'], $year['closing_balance_cents']);
    }

    public function test_transaction_dates_respect_month_boundaries(): void
    {
        $this->transaction($this->bruno, TransactionType::Income, 1_000, '2026-09-30');
        $this->transaction($this->bruno, TransactionType::Income, 2_000, '2026-10-01');
        $this->transaction($this->bruno, TransactionType::Income, 4_000, '2026-10-31');
        $this->transaction($this->bruno, TransactionType::Income, 8_000, '2026-11-01');

        $this->assertSame(6_000, app(PersonalSummary::class)->month($this->bruno, Month::parse('2026-10'))['income_cents']);
    }

    public function test_transaction_validation(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('personal.transactions.store', 'expenses'), [
                'description' => '',
                'category' => str_repeat('a', 61),
                'amount' => '0',
                'transaction_date' => '2026-02-30',
            ])
            ->assertSessionHasErrors(['description', 'category', 'amount', 'transaction_date']);

        foreach (['-10.00', '1,50', '10.999', 'abc', '10000000.00'] as $amount) {
            $this->actingAs($this->bruno)
                ->post(route('personal.transactions.store', 'expenses'), [
                    'description' => 'Teste', 'category' => 'Teste', 'amount' => $amount, 'transaction_date' => '2026-10-01',
                ])
                ->assertSessionHasErrors('amount');
        }

        $this->actingAs($this->bruno)->post('/personal/gifts', [])->assertNotFound();
        $this->assertDatabaseCount('personal_transactions', 0);
    }
}
