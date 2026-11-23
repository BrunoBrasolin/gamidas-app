<?php

namespace Tests\Feature\Bank;

use App\Enums\BankAccountType;
use App\Enums\BankCatalogType;
use App\Enums\OccurrenceStatus;
use App\Enums\RecurringKind;
use App\Enums\TransactionType;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankRule;
use App\Models\BankSplitItem;
use App\Models\BankTransaction;
use App\Models\ExpenseOccurrence;
use App\Models\PersonalTransaction;
use App\Services\ExpenseService;
use App\Services\RecurringExpenseService;
use App\Support\Month;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\Concerns\FakesPluggy;
use Tests\TestCase;

/**
 * "Dividir valor": one transaction catalogued in parts, e.g. R$ 170,00 of a
 * R$ 454,41 purchase pay an installment already registered and the rest is
 * a personal expense.
 */
class BankSplitTest extends TestCase
{
    use CreatesHousehold, FakesPluggy, RefreshDatabase;

    private BankConnection $connection;

    private BankAccount $checking;

    private BankAccount $card;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-11-19 12:00:00');
        $this->configurePluggy();
        $this->createHousehold();
        $this->connection = $this->createBankConnection($this->bruno);
        $this->checking = $this->createBankAccount($this->connection, BankAccountType::Bank, 'acc-checking');
        $this->card = $this->createBankAccount($this->connection, BankAccountType::Credit, 'acc-card');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    private function purchase(float $amount = -454.41, string $date = '2026-11-08'): BankTransaction
    {
        return $this->createBankTransaction($this->checking, [
            'date' => $date.'T00:00:00.000Z',
            'description' => 'Loja Exemplo',
            'amount' => $amount,
            'type' => 'DEBIT',
        ]);
    }

    private function installment(int $cents = 17_000): ExpenseOccurrence
    {
        return app(ExpenseService::class)->create($this->household, $this->leticia, [
            'title' => 'Instalação alarme',
            'notes' => null,
            'amount_cents' => $cents,
            'due_date' => Month::current()->addDays(9),
            'installment_number' => 11,
            'installment_count' => 12,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $parts
     * @return array<string, mixed>
     */
    private function split(array $parts, array $extra = []): array
    {
        return ['action' => 'split', 'parts' => $parts, ...$extra];
    }

    public function test_part_pays_an_existing_expense_and_the_rest_is_a_personal_expense(): void
    {
        $installment = $this->installment();
        $transaction = $this->purchase();

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $transaction), $this->split([
                ['action' => 'link', 'amount' => '170.00', 'occurrence_id' => $installment->id],
                ['action' => 'personal', 'amount' => '284.41', 'title' => 'Roupas'],
            ], ['date' => '2026-11-08', 'remember' => '1', 'auto_apply' => '1']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $transaction->refresh();
        $this->assertTrue($transaction->isCatalogued());
        $this->assertSame(BankCatalogType::Split, $transaction->catalog_type);
        $this->assertNull($transaction->expense_occurrence_id);
        $this->assertNull($transaction->personal_transaction_id);

        $items = $transaction->splitItems;
        $this->assertSame([BankCatalogType::Linked, BankCatalogType::Personal], $items->pluck('type')->all());
        $this->assertSame([17_000, 28_441], $items->pluck('amount_cents')->all());

        // Letícia registered the installment, so Bruno's bank pays it.
        $installment->refresh();
        $this->assertSame($installment->id, $items[0]->expense_occurrence_id);
        $this->assertSame(OccurrenceStatus::Paid, $installment->status);
        $this->assertSame(17_000, $installment->amount_cents);
        $this->assertSame($this->bruno->id, $installment->paid_by_user_id);
        $this->assertSame('2026-11-08', $installment->paid_at->toDateString());

        $entry = PersonalTransaction::query()->sole();
        $this->assertSame($entry->id, $items[1]->personal_transaction_id);
        $this->assertSame(TransactionType::Expense, $entry->type);
        $this->assertSame('Roupas', $entry->title);
        $this->assertSame(28_441, $entry->amount_cents);
        $this->assertSame('2026-11-08', $entry->transaction_date->toDateString());

        // A split changes every time: it never becomes a rule.
        $this->assertSame(0, BankRule::query()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'bank.catalogued', 'auditable_id' => $transaction->id]);

        $this->actingAs($this->bruno)
            ->get(route('bank.inbox', ['status' => 'catalogued']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('transactions.data.0.catalog_type', 'split')
                ->has('transactions.data.0.split', 2)
                ->where('transactions.data.0.split.0.amount_cents', 17_000)
                ->where('transactions.data.0.split.0.expense.title', 'Instalação alarme')
                ->where('transactions.data.0.split.1.amount_cents', 28_441)
                ->where('transactions.data.0.split.1.entry.title', 'Roupas'));
    }

    public function test_the_value_can_be_split_between_the_house_and_personal_expenses(): void
    {
        $transaction = $this->createBankTransaction($this->card, ['description' => 'Supermercado Exemplo', 'amount' => 452.3, 'date' => '2026-11-12T00:00:00.000Z']);

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $transaction), $this->split([
                ['action' => 'household', 'amount' => '400.00', 'title' => 'Mercado do mês'],
                ['action' => 'personal', 'amount' => '32.30', 'title' => 'Chocolate'],
                ['action' => 'investment', 'amount' => '20.00'],
            ], ['date' => '2026-12-10']))
            ->assertSessionHasNoErrors();

        $occurrence = ExpenseOccurrence::query()->sole();
        $this->assertSame('Mercado do mês', $occurrence->title);
        $this->assertSame(40_000, $occurrence->amount_cents);
        $this->assertSame('2026-12-10', $occurrence->due_date->toDateString());
        $this->assertSame(OccurrenceStatus::Pending, $occurrence->status);
        $this->assertSame([$this->bruno->id => 32_000, $this->leticia->id => 8_000], $this->sharesOf($occurrence));

        $entries = PersonalTransaction::query()->orderBy('id')->get();
        $this->assertSame([TransactionType::Expense, TransactionType::Investment], $entries->pluck('type')->all());
        $this->assertSame([3_230, 2_000], $entries->pluck('amount_cents')->all());
        $this->assertSame('Supermercado Exemplo', $entries[1]->title);

        // Letícia confirms the household part seeing Bruno's bank proof.
        $this->actingAs($this->leticia)
            ->get(route('expenses.index', ['month' => '2026-12']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('occurrences.data.0.id', $occurrence->id)
                ->where('occurrences.data.0.bank_proof.member', 'Bruno')
                ->where('occurrences.data.0.bank_proof.date', '2026-11-12'));
    }

    public function test_a_linked_part_gives_the_pending_expense_the_parts_value(): void
    {
        $installment = $this->installment(15_000);

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $this->purchase()), $this->split([
                ['action' => 'link', 'amount' => '170.00', 'occurrence_id' => $installment->id],
                ['action' => 'personal', 'amount' => '284.41'],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(17_000, $installment->fresh()->amount_cents);
    }

    public function test_the_parts_must_add_up_to_the_transaction(): void
    {
        $installment = $this->installment();
        $transaction = $this->purchase();

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $transaction), $this->split([
                ['action' => 'link', 'amount' => '170.00', 'occurrence_id' => $installment->id],
                ['action' => 'personal', 'amount' => '200.00'],
            ]))
            ->assertSessionHasErrors(['parts' => 'As partes somam R$ 370,00, mas a transação é de R$ 454,41.']);

        $this->assertTrue($transaction->fresh()->isPending());
        $this->assertTrue($installment->fresh()->status === OccurrenceStatus::Pending);
        $this->assertSame(0, PersonalTransaction::query()->count());
        $this->assertSame(0, BankSplitItem::query()->count());
    }

    public function test_each_part_needs_a_value_a_type_and_an_expense_to_link(): void
    {
        $transaction = $this->purchase();

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $transaction), $this->split([
                ['action' => 'personal', 'amount' => '454.41'],
            ]))
            ->assertSessionHasErrors('parts');

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $transaction), $this->split([
                ['action' => 'link', 'amount' => '170.00'],
                ['action' => 'income', 'amount' => '284.41'],
                ['action' => 'personal', 'amount' => '0.00'],
            ]))
            ->assertSessionHasErrors(['parts.0.occurrence_id', 'parts.1.action', 'parts.2.amount']);

        $this->assertTrue($transaction->fresh()->isPending());
    }

    public function test_an_expense_goes_into_one_part_only_and_once_linked_is_taken(): void
    {
        $installment = $this->installment();
        $transaction = $this->purchase();

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $transaction), $this->split([
                ['action' => 'link', 'amount' => '170.00', 'occurrence_id' => $installment->id],
                ['action' => 'link', 'amount' => '284.41', 'occurrence_id' => $installment->id],
            ]))
            ->assertSessionHasErrors('parts');

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $transaction), $this->split([
                ['action' => 'link', 'amount' => '170.00', 'occurrence_id' => $installment->id],
                ['action' => 'personal', 'amount' => '284.41'],
            ]))
            ->assertSessionHasNoErrors();

        // Another transaction cannot take it, as a whole or as a part.
        $other = $this->purchase(-170, '2026-11-09');

        $this->actingAs($this->bruno)
            ->get(route('bank.inbox'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('transactions.data.0.id', $other->id)
                ->where('transactions.data.0.candidates', [])
                ->where('linkable', fn ($linkable) => ! collect($linkable)->pluck('id')->contains($installment->id)));

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $other), ['action' => 'link', 'occurrence_id' => $installment->id])
            ->assertSessionHasErrors('occurrence_id');

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $this->purchase(-200, '2026-11-10')), $this->split([
                ['action' => 'link', 'amount' => '170.00', 'occurrence_id' => $installment->id],
                ['action' => 'personal', 'amount' => '30.00'],
            ]))
            ->assertSessionHasErrors('parts.0.occurrence_id');
    }

    public function test_money_coming_in_cannot_be_split(): void
    {
        $refund = $this->createBankTransaction($this->checking, ['description' => 'Reembolso', 'amount' => 100, 'type' => 'CREDIT']);

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $refund), $this->split([
                ['action' => 'personal', 'amount' => '50.00'],
                ['action' => 'personal', 'amount' => '50.00'],
            ]))
            ->assertSessionHasErrors('action');

        $this->assertTrue($refund->fresh()->isPending());
    }

    public function test_undo_removes_what_the_parts_created_and_keeps_linked_expenses_paid(): void
    {
        $installment = $this->installment();
        $transaction = $this->purchase();

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $transaction), $this->split([
                ['action' => 'link', 'amount' => '170.00', 'occurrence_id' => $installment->id],
                ['action' => 'household', 'amount' => '84.41', 'title' => 'Lâmpadas'],
                ['action' => 'personal', 'amount' => '200.00'],
            ]))
            ->assertSessionHasNoErrors();

        $created = ExpenseOccurrence::query()->where('title', 'Lâmpadas')->sole();

        $this->actingAs($this->bruno)->delete(route('bank.transactions.reopen', $transaction))->assertSessionHasNoErrors();

        $transaction->refresh();
        $this->assertTrue($transaction->isPending());
        $this->assertNull($transaction->catalog_type);
        $this->assertSame(0, BankSplitItem::query()->count());
        $this->assertSoftDeleted($created);
        $this->assertSame(0, PersonalTransaction::query()->count());
        $this->assertTrue($installment->fresh()->isPaid());
        $this->assertDatabaseHas('audit_logs', ['action' => 'bank.reopened', 'auditable_id' => $transaction->id]);

        // Free again: the installment can be linked to the transaction anew.
        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $transaction), $this->split([
                ['action' => 'link', 'amount' => '170.00', 'occurrence_id' => $installment->id],
                ['action' => 'personal', 'amount' => '284.41'],
            ]))
            ->assertSessionHasNoErrors();
    }

    public function test_undo_is_refused_once_a_household_part_was_paid(): void
    {
        $transaction = $this->purchase();

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $transaction), $this->split([
                ['action' => 'household', 'amount' => '254.41'],
                ['action' => 'personal', 'amount' => '200.00'],
            ]))
            ->assertSessionHasNoErrors();

        $occurrence = ExpenseOccurrence::query()->sole();
        $this->actingAs($this->leticia)->post(route('expenses.payment.store', $occurrence))->assertSessionHasNoErrors();

        $this->actingAs($this->bruno)->delete(route('bank.transactions.reopen', $transaction))->assertSessionHasErrors('transaction');

        $this->assertTrue($transaction->fresh()->isCatalogued());
        $this->assertSame(2, BankSplitItem::query()->count());
        $this->assertSame(1, PersonalTransaction::query()->count());
    }

    public function test_an_expense_backed_by_a_part_moves_to_personal_only_with_the_banks_owner(): void
    {
        $template = app(RecurringExpenseService::class)->create($this->household, $this->bruno, [
            'kind' => RecurringKind::Fixed,
            'title' => 'Internet',
            'notes' => null,
            'amount_cents' => 12_000,
            'due_day' => 10,
            'start_month' => Month::parse('2026-10'),
            'end_month' => null,
            'total_months' => null,
        ]);
        $month = $template->occurrences()->forMonth(Month::parse('2026-11'))->sole();
        $transaction = $this->purchase(-200);

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $transaction), $this->split([
                ['action' => 'link', 'amount' => '120.00', 'occurrence_id' => $month->id],
                ['action' => 'household', 'amount' => '80.00', 'title' => 'Cabo de rede'],
            ]))
            ->assertSessionHasNoErrors();

        $created = ExpenseOccurrence::query()->where('title', 'Cabo de rede')->sole();

        // Backed by Bruno's bank: only Bruno takes them.
        $this->actingAs($this->leticia)->post(route('expenses.move-to-personal', $month))->assertForbidden();
        $this->actingAs($this->leticia)->post(route('expenses.move-to-personal', $created))->assertForbidden();

        $this->actingAs($this->bruno)->post(route('expenses.move-to-personal', $month))->assertSessionHasNoErrors();
        $this->actingAs($this->bruno)->post(route('expenses.move-to-personal', $created))->assertSessionHasNoErrors();

        // Both parts follow their expenses into Bruno's personal entries.
        $this->assertSoftDeleted($month);
        $this->assertSoftDeleted($created);
        $internet = PersonalTransaction::query()->where('title', 'Internet')->sole();
        $cable = PersonalTransaction::query()->where('title', 'Cabo de rede')->sole();
        $parts = $transaction->splitItems()->orderBy('id')->get();
        $this->assertSame([BankCatalogType::Personal, BankCatalogType::Personal], $parts->pluck('type')->all());
        $this->assertSame([$internet->id, $cable->id], $parts->pluck('personal_transaction_id')->all());
        $this->assertSame([null, null], $parts->pluck('expense_occurrence_id')->all());

        $this->actingAs($this->bruno)
            ->get(route('bank.inbox', ['status' => 'catalogued']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('transactions.data.0.split.0.label', 'Despesa pessoal')
                ->where('transactions.data.0.split.0.entry.id', $internet->id)
                ->where('transactions.data.0.split.1.entry.id', $cable->id));

        // Undoing the split removes the entries.
        $this->actingAs($this->bruno)->delete(route('bank.transactions.reopen', $transaction))->assertSessionHasNoErrors();
        $this->assertSame(0, PersonalTransaction::query()->count());
    }
}
