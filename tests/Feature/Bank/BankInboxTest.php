<?php

namespace Tests\Feature\Bank;

use App\Enums\BankAccountType;
use App\Enums\BankCatalogType;
use App\Enums\BankReviewStatus;
use App\Enums\BankRuleAction;
use App\Enums\ExpenseScope;
use App\Enums\OccurrenceStatus;
use App\Enums\RecurringKind;
use App\Enums\TransactionType;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankRule;
use App\Models\BankTransaction;
use App\Models\ExpenseOccurrence;
use App\Models\PersonalTransaction;
use App\Services\Bank\BankCatalogService;
use App\Services\Bank\BankSyncService;
use App\Services\Bank\CatalogChoice;
use App\Services\ExpenseService;
use App\Services\OccurrenceGenerator;
use App\Services\RecurringExpenseService;
use App\Support\Month;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\Concerns\FakesPluggy;
use Tests\TestCase;

class BankInboxTest extends TestCase
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

    private function pix(string $name = 'FULANO DE TAL', float $amount = -1800, string $date = '2026-11-05'): BankTransaction
    {
        return $this->createBankTransaction($this->checking, [
            'date' => $date.'T00:00:00.000Z',
            'description' => 'Transferência enviada',
            'amount' => $amount,
            'type' => 'DEBIT',
            'operationType' => 'PIX',
            'paymentData' => ['paymentMethod' => 'PIX', 'receiver' => ['name' => $name]],
        ]);
    }

    public function test_the_inbox_lists_only_the_owners_pending_transactions(): void
    {
        $mine = $this->pix();
        $leticiaConnection = $this->createBankConnection($this->leticia, 'item-leticia');
        $this->createBankTransaction($this->createBankAccount($leticiaConnection), ['description' => 'Da Letícia']);

        $this->actingAs($this->bruno)
            ->get(route('bank.inbox'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('bank/inbox')
                ->where('counts.pending', 1)
                ->has('transactions.data', 1)
                ->where('transactions.data.0.id', $mine->id)
                ->where('transactions.data.0.display_name', 'Fulano De Tal')
                ->where('transactions.data.0.direction', 'out'));
    }

    public function test_other_members_cannot_touch_a_transaction(): void
    {
        $transaction = $this->pix();

        $this->actingAs($this->leticia)
            ->post(route('bank.transactions.catalog', $transaction), ['action' => 'ignore'])
            ->assertNotFound();
        $this->actingAs($this->leticia)->post(route('bank.transactions.confirm', $transaction))->assertNotFound();
        $this->actingAs($this->leticia)->delete(route('bank.transactions.reopen', $transaction))->assertNotFound();

        $this->assertTrue($transaction->fresh()->isPending());
    }

    public function test_a_household_expense_waits_for_the_other_member_who_sees_the_bank_proof(): void
    {
        $transaction = $this->createBankTransaction($this->card, ['description' => 'Supermercado Exemplo', 'amount' => 452.3, 'date' => '2026-11-12T00:00:00.000Z']);

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $transaction), [
                'action' => 'household',
                'title' => 'Mercado do mês',
                'date' => '2026-12-10',
                'remember' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $transaction->refresh();
        $occurrence = $transaction->expenseOccurrence;

        $this->assertSame(BankCatalogType::Household, $transaction->catalog_type);
        $this->assertSame(ExpenseScope::Household, $occurrence->scope);
        $this->assertSame('Mercado do mês', $occurrence->title);
        $this->assertSame(45230, $occurrence->amount_cents);
        $this->assertSame('2026-12', Month::key($occurrence->billing_month));
        $this->assertSame($this->bruno->id, $occurrence->created_by_user_id);
        $this->assertSame(OccurrenceStatus::Pending, $occurrence->status);
        // Split 80/20 by salary.
        $this->assertSame([$this->bruno->id => 36184, $this->leticia->id => 9046], $this->sharesOf($occurrence));

        // Bruno created it, so only Letícia confirms the payment.
        $this->actingAs($this->bruno)->post(route('expenses.payment.store', $occurrence))->assertForbidden();

        $this->actingAs($this->leticia)
            ->get(route('expenses.index', ['month' => '2026-12']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('occurrences.data.0.id', $occurrence->id)
                ->where('occurrences.data.0.bank_proof.member', 'Bruno')
                ->where('occurrences.data.0.bank_proof.date', '2026-11-12'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'bank.catalogued', 'auditable_id' => $transaction->id, 'user_id' => $this->bruno->id]);
        $this->assertDatabaseHas('bank_rules', ['user_id' => $this->bruno->id, 'pattern' => 'supermercado exemplo', 'action' => 'household', 'title' => 'Mercado do mês']);
    }

    public function test_card_installments_keep_their_number(): void
    {
        $transaction = $this->createBankTransaction($this->card, [
            'description' => 'Loja de Móveis',
            'amount' => 300,
            'creditCardMetadata' => ['installmentNumber' => 2, 'totalInstallments' => 10],
        ]);

        app(BankCatalogService::class)->catalog($transaction, $this->bruno, new CatalogChoice('household'));

        $occurrence = $transaction->fresh()->expenseOccurrence;
        $this->assertSame(2, $occurrence->installment_number);
        $this->assertSame(10, $occurrence->installment_count);
    }

    public function test_personal_income_and_investments_go_to_the_personal_ledger(): void
    {
        $purchase = $this->createBankTransaction($this->card, ['description' => 'Livraria', 'amount' => 59.9]);
        $salary = $this->createBankTransaction($this->checking, ['description' => 'Salário Empresa', 'amount' => 8000, 'type' => 'CREDIT']);
        $savings = $this->createBankTransaction($this->checking, ['description' => 'Aplicação RDB', 'amount' => -500, 'type' => 'DEBIT']);

        $this->actingAs($this->bruno)->post(route('bank.transactions.catalog', $purchase), ['action' => 'personal', 'title' => 'Livro'])->assertSessionHasNoErrors();
        $this->actingAs($this->bruno)->post(route('bank.transactions.catalog', $salary), ['action' => 'income'])->assertSessionHasNoErrors();
        $this->actingAs($this->bruno)->post(route('bank.transactions.catalog', $savings), ['action' => 'investment'])->assertSessionHasNoErrors();

        $entries = PersonalTransaction::query()->where('user_id', $this->bruno->id)->orderBy('id')->get();
        $this->assertSame([TransactionType::Expense, TransactionType::Income, TransactionType::Investment], $entries->pluck('type')->all());
        $this->assertSame([5990, 800000, 50000], $entries->pluck('amount_cents')->all());
        $this->assertSame('Livro', $entries[0]->title);
        $this->assertSame('Salário Empresa', $entries[1]->title);
        $this->assertSame(0, ExpenseOccurrence::query()->count());
    }

    public function test_money_in_cannot_be_an_expense_and_money_out_cannot_be_income(): void
    {
        $salary = $this->createBankTransaction($this->checking, ['amount' => 8000, 'type' => 'CREDIT']);
        $purchase = $this->pix();

        $this->actingAs($this->bruno)->post(route('bank.transactions.catalog', $salary), ['action' => 'household'])->assertSessionHasErrors('action');
        $this->actingAs($this->bruno)->post(route('bank.transactions.catalog', $purchase), ['action' => 'income'])->assertSessionHasErrors('action');

        $this->assertTrue($salary->fresh()->isPending());
        $this->assertTrue($purchase->fresh()->isPending());
    }

    public function test_linking_to_an_expense_of_the_other_member_confirms_the_payment_on_the_bank_date(): void
    {
        $rent = $this->createExpense($this->leticia, 180_000, Month::current());
        $transaction = $this->pix();

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $transaction), ['action' => 'link', 'occurrence_id' => $rent->id])
            ->assertSessionHasNoErrors();

        $rent->refresh();
        $this->assertSame(OccurrenceStatus::Paid, $rent->status);
        $this->assertSame($this->bruno->id, $rent->paid_by_user_id);
        $this->assertSame('2026-11-05', $rent->paid_at->toDateString());
        $this->assertSame(BankCatalogType::Linked, $transaction->fresh()->catalog_type);
        $this->assertSame($rent->id, $transaction->fresh()->expense_occurrence_id);
    }

    public function test_linking_to_an_expense_the_owner_created_leaves_it_for_the_other_member(): void
    {
        $bill = $this->createExpense($this->bruno, 180_000, Month::current());
        $transaction = $this->pix();

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $transaction), ['action' => 'link', 'occurrence_id' => $bill->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(OccurrenceStatus::Pending, $bill->fresh()->status);
        $this->assertTrue($this->leticia->can('confirmPayment', $bill->fresh()));
    }

    public function test_linking_fills_the_real_amount_of_a_variable_bill(): void
    {
        $template = app(RecurringExpenseService::class)->create($this->household, $this->leticia, [
            'scope' => ExpenseScope::Household,
            'kind' => RecurringKind::Fixed,
            'title' => 'Conta de luz',
            'notes' => null,
            'amount_cents' => 0,
            'is_variable' => true,
            'due_day' => 10,
            'start_month' => Month::current(),
            'end_month' => null,
            'total_months' => null,
        ]);
        $occurrence = $template->occurrences()->forMonth(Month::current())->sole();
        $this->assertTrue($occurrence->awaitsAmount());

        $transaction = $this->createBankTransaction($this->checking, ['description' => 'Pagamento de boleto - Energia', 'amount' => -231.47, 'date' => '2026-11-09T00:00:00.000Z']);

        app(BankCatalogService::class)->catalog($transaction, $this->bruno, new CatalogChoice('link', occurrenceId: $occurrence->id));

        $occurrence->refresh();
        $this->assertSame(23147, $occurrence->amount_cents);
        $this->assertTrue($occurrence->is_customized);
        $this->assertSame(OccurrenceStatus::Paid, $occurrence->status);

        // The rule learned points to the recurring expense, so next month's
        // bill suggests next month's occurrence.
        $rule = BankRule::query()->sole();
        $this->assertSame(BankRuleAction::Recurring, $rule->action);
        $this->assertSame($template->id, $rule->recurring_expense_id);

        $next = $this->createBankTransaction($this->checking, ['description' => 'Pagamento de boleto - Energia', 'amount' => -198.03, 'date' => '2026-12-09T00:00:00.000Z']);
        app(OccurrenceGenerator::class)->generateForHousehold($this->household, Month::current()->addMonth());

        $suggestion = app(BankCatalogService::class)->suggest($next->fresh());
        $this->assertNotNull($suggestion);
        $this->assertSame('link', $suggestion->choice->action);
        $this->assertSame('2026-12', Month::key($suggestion->occurrence->billing_month));
    }

    public function test_a_learned_rule_suggests_and_one_click_confirms(): void
    {
        $first = $this->pix();
        $this->actingAs($this->bruno)->post(route('bank.transactions.catalog', $first), ['action' => 'personal', 'title' => 'Aluguel do escritório', 'remember' => '1']);

        $second = $this->pix(date: '2026-11-15');

        $this->actingAs($this->bruno)
            ->get(route('bank.inbox'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('transactions.data.0.id', $second->id)
                ->where('transactions.data.0.suggestion.action', 'personal')
                ->where('transactions.data.0.suggestion.title', 'Aluguel do escritório')
                ->where('transactions.data.0.suggestion.rule.label', 'Fulano De Tal'));

        $this->actingAs($this->bruno)->post(route('bank.transactions.confirm', $second))->assertSessionHasNoErrors();

        $this->assertSame('Aluguel do escritório', $second->fresh()->personalTransaction->title);
        $this->assertSame(2, BankRule::query()->sole()->hits);
    }

    public function test_adjusting_a_suggestion_updates_the_rule(): void
    {
        $this->actingAs($this->bruno)->post(route('bank.transactions.catalog', $this->pix()), ['action' => 'personal', 'remember' => '1']);
        $this->actingAs($this->bruno)->post(route('bank.transactions.catalog', $this->pix(date: '2026-11-15')), ['action' => 'household', 'title' => 'Aluguel', 'remember' => '1']);

        $rule = BankRule::query()->sole();
        $this->assertSame(BankRuleAction::Household, $rule->action);
        $this->assertSame('Aluguel', $rule->title);
    }

    public function test_not_remembering_creates_no_rule(): void
    {
        $this->actingAs($this->bruno)->post(route('bank.transactions.catalog', $this->pix()), ['action' => 'ignore', 'remember' => '0']);

        $this->assertSame(0, BankRule::query()->count());
        $this->assertSame(BankReviewStatus::Ignored, BankTransaction::query()->sole()->review_status);
    }

    public function test_rules_set_to_apply_automatically_catalogue_new_imports(): void
    {
        BankRule::create([
            'user_id' => $this->bruno->id,
            'pattern' => 'padaria pao quente',
            'label' => 'Padaria Pão Quente',
            'action' => BankRuleAction::Household,
            'title' => 'Padaria',
            'auto_apply' => true,
        ]);

        $this->pluggyItem();
        $this->pluggyTransaction('acc-card', 'tx-bread', ['description' => 'Padaria Pão Quente', 'amount' => 18.5]);
        $this->fakePluggy();

        app(BankSyncService::class)->sync($this->connection);

        $transaction = BankTransaction::query()->firstWhere('pluggy_id', 'tx-bread');
        $this->assertSame(BankCatalogType::Household, $transaction->catalog_type);
        $this->assertSame('Padaria', $transaction->expenseOccurrence->title);
        $this->assertSame(1, BankRule::query()->sole()->hits);
    }

    public function test_contains_rules_match_and_exact_rules_win(): void
    {
        BankRule::create(['user_id' => $this->bruno->id, 'pattern' => 'uber', 'match_mode' => 'contains', 'label' => 'uber', 'action' => BankRuleAction::Personal]);
        BankRule::create(['user_id' => $this->bruno->id, 'pattern' => 'uber eats', 'label' => 'Uber Eats', 'action' => BankRuleAction::Household, 'title' => 'Delivery']);

        $trip = $this->createBankTransaction($this->card, ['description' => 'UBER *TRIP 1234']);
        $food = $this->createBankTransaction($this->card, ['description' => 'Uber Eats']);

        $catalog = app(BankCatalogService::class);
        $this->assertSame('personal', $catalog->suggest($trip)?->choice->action);
        $this->assertSame('household', $catalog->suggest($food)?->choice->action);
    }

    public function test_an_expense_with_the_same_amount_and_installment_is_suggested(): void
    {
        $typed = app(ExpenseService::class)->create($this->household, $this->leticia, [
            'scope' => ExpenseScope::Household,
            'title' => 'Sofá',
            'notes' => null,
            'amount_cents' => 30000,
            'due_date' => $this->day('2026-10-10'),
        ], 10);
        $third = ExpenseOccurrence::query()->where('installment_number', 3)->sole();

        $transaction = $this->createBankTransaction($this->card, [
            'description' => 'Loja de Móveis',
            'amount' => 300,
            'date' => '2026-11-28T00:00:00.000Z',
            'creditCardMetadata' => ['installmentNumber' => 3, 'totalInstallments' => 10],
        ]);

        $suggestion = app(BankCatalogService::class)->suggest($transaction);

        $this->assertNotNull($suggestion);
        $this->assertSame('link', $suggestion->choice->action);
        $this->assertSame($third->id, $suggestion->occurrence->id);
        $this->assertNotSame($typed->id, $third->id);
    }

    public function test_confirm_all_applies_every_suggestion(): void
    {
        BankRule::create(['user_id' => $this->bruno->id, 'pattern' => 'fulano de tal', 'label' => 'Fulano de Tal', 'action' => BankRuleAction::Personal]);
        $this->pix();
        $this->pix(date: '2026-11-06');
        $other = $this->createBankTransaction($this->card, ['description' => 'Sem regra']);

        $this->actingAs($this->bruno)->post(route('bank.inbox.confirm-all'))->assertSessionHasNoErrors();

        $this->assertSame(2, PersonalTransaction::query()->count());
        $this->assertTrue($other->fresh()->isPending());
    }

    public function test_undo_removes_the_created_expense_and_brings_the_transaction_back(): void
    {
        $transaction = $this->createBankTransaction($this->card);
        $this->actingAs($this->bruno)->post(route('bank.transactions.catalog', $transaction), ['action' => 'household']);
        $occurrence = $transaction->fresh()->expenseOccurrence;

        $this->actingAs($this->bruno)->delete(route('bank.transactions.reopen', $transaction))->assertSessionHasNoErrors();

        $this->assertTrue($transaction->fresh()->isPending());
        $this->assertNull($transaction->fresh()->expense_occurrence_id);
        $this->assertSoftDeleted($occurrence);
        $this->assertDatabaseHas('audit_logs', ['action' => 'bank.reopened', 'auditable_id' => $transaction->id]);
    }

    public function test_undo_is_refused_once_the_created_expense_was_paid(): void
    {
        $transaction = $this->createBankTransaction($this->card);
        $this->actingAs($this->bruno)->post(route('bank.transactions.catalog', $transaction), ['action' => 'household']);
        $occurrence = $transaction->fresh()->expenseOccurrence;
        $this->actingAs($this->leticia)->post(route('expenses.payment.store', $occurrence))->assertSessionHasNoErrors();

        $this->actingAs($this->bruno)->delete(route('bank.transactions.reopen', $transaction))->assertSessionHasErrors('transaction');

        $this->assertTrue($transaction->fresh()->isCatalogued());
    }

    public function test_undo_deletes_the_personal_entry_and_keeps_linked_expenses(): void
    {
        $purchase = $this->createBankTransaction($this->card);
        $this->actingAs($this->bruno)->post(route('bank.transactions.catalog', $purchase), ['action' => 'personal']);
        $this->actingAs($this->bruno)->delete(route('bank.transactions.reopen', $purchase));
        $this->assertSame(0, PersonalTransaction::query()->count());

        $rent = $this->createExpense($this->leticia, 180_000);
        $pix = $this->pix();
        $this->actingAs($this->bruno)->post(route('bank.transactions.catalog', $pix), ['action' => 'link', 'occurrence_id' => $rent->id]);
        $this->actingAs($this->bruno)->delete(route('bank.transactions.reopen', $pix));

        $this->assertTrue($pix->fresh()->isPending());
        $this->assertTrue($rent->fresh()->isPaid());
    }

    public function test_an_expense_can_be_linked_to_one_transaction_only_and_must_be_visible(): void
    {
        $rent = $this->createExpense($this->leticia, 180_000);
        $leticiaPrivate = $this->createExpense($this->leticia, 5_000, null, ExpenseScope::Personal);

        $this->actingAs($this->bruno)->post(route('bank.transactions.catalog', $this->pix()), ['action' => 'link', 'occurrence_id' => $rent->id]);

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $this->pix(date: '2026-11-06')), ['action' => 'link', 'occurrence_id' => $rent->id])
            ->assertSessionHasErrors('occurrence_id');

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $this->pix(date: '2026-11-07')), ['action' => 'link', 'occurrence_id' => $leticiaPrivate->id])
            ->assertSessionHasErrors('occurrence_id');
    }

    public function test_card_purchases_count_on_the_due_date_of_their_bill(): void
    {
        $this->card->bills()->create(['pluggy_id' => 'bill-nov', 'closing_date' => '2026-11-03', 'due_date' => '2026-11-10', 'total_cents' => 100]);

        $catalog = app(BankCatalogService::class);

        // In a closed bill.
        $closed = $this->createBankTransaction($this->card, ['date' => '2026-10-20T00:00:00.000Z', 'creditCardMetadata' => ['billId' => 'bill-nov']]);
        $this->assertSame('2026-11-10', $catalog->referenceDate($closed->fresh())->toDateString());

        // After the last closing: the next bill, a month later.
        $open = $this->createBankTransaction($this->card, ['date' => '2026-11-15T00:00:00.000Z', 'status' => 'PENDING']);
        $this->assertSame('2026-12-10', $catalog->referenceDate($open->fresh())->toDateString());

        // Account movements count on their own day.
        $this->assertSame('2026-11-05', $catalog->referenceDate($this->pix())->toDateString());
    }

    public function test_rules_by_value_tell_apart_payments_to_the_same_person(): void
    {
        $house = app(RecurringExpenseService::class)->create($this->household, $this->leticia, [
            'scope' => ExpenseScope::Household,
            'kind' => RecurringKind::Fixed,
            'title' => 'Parcela da casa',
            'notes' => null,
            'amount_cents' => 150_000,
            'due_day' => 5,
            'start_month' => Month::current(),
            'end_month' => null,
            'total_months' => null,
        ]);
        $november = $house->occurrences()->forMonth(Month::current())->sole();

        // R$ 1.500,00 to Letícia is the house: remembered for any value.
        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $this->pix('LETICIA EXEMPLO', -1500, '2026-11-05')), [
                'action' => 'link', 'occurrence_id' => $november->id, 'remember' => '1',
            ])
            ->assertSessionHasNoErrors();

        // R$ 120,00 to her comes suggested as the house too; adjusted to the
        // gym, remembered only for this value.
        $gym = $this->pix('LETICIA EXEMPLO', -120, '2026-11-06');
        $this->assertSame('link', app(BankCatalogService::class)->suggest($gym)?->choice->action);

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $gym), [
                'action' => 'personal', 'title' => 'Academia', 'remember' => '1', 'remember_amount' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, BankRule::query()->count());
        $this->assertSame(12_000, BankRule::query()->where('action', BankRuleAction::Personal)->sole()->amount_cents);
        $this->assertNull(BankRule::query()->where('action', BankRuleAction::Recurring)->sole()->amount_cents);

        // Next month each value gets its own suggestion.
        $catalog = app(BankCatalogService::class);
        $nextHouse = $catalog->suggest($this->pix('LETICIA EXEMPLO', -1500, '2026-12-05'));
        $nextGym = $catalog->suggest($this->pix('LETICIA EXEMPLO', -120, '2026-12-06'));

        $this->assertSame('link', $nextHouse?->choice->action);
        $this->assertSame('2026-12', Month::key($nextHouse->occurrence->billing_month));
        $this->assertSame('personal', $nextGym?->choice->action);
        $this->assertSame('Academia', $nextGym->choice->title);

        // Other values to her still fall back to the rule for any value.
        $this->assertSame('link', $catalog->suggest($this->pix('LETICIA EXEMPLO', -99, '2026-12-07'))?->choice->action);
    }

    public function test_any_pending_expense_can_be_picked_even_without_suggestions(): void
    {
        $rent = $this->createExpense($this->leticia, 180_000);
        $leticiaPrivate = $this->createExpense($this->leticia, 5_000, null, ExpenseScope::Personal);
        $mine = $this->createExpense($this->bruno, 7_000, null, ExpenseScope::Personal);
        $transaction = $this->pix('MARIA EXEMPLO', -1500);

        $this->actingAs($this->bruno)
            ->get(route('bank.inbox'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('transactions.data.0.candidates', [])
                ->where('linkable', fn ($linkable) => collect($linkable)->pluck('id')->sort()->values()->all() === collect([$rent->id, $mine->id])->sort()->values()->all()));

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $transaction), ['action' => 'link', 'occurrence_id' => $rent->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($rent->id, $transaction->fresh()->expense_occurrence_id);
        $this->assertSame(150_000, $rent->fresh()->amount_cents);
        $this->assertNotSame($leticiaPrivate->id, $transaction->fresh()->expense_occurrence_id);
    }

    public function test_rules_learned_from_the_description_keep_matching_once_the_name_is_known(): void
    {
        // Learned before the counterparty was read from "Pix|NAME".
        BankRule::create([
            'user_id' => $this->bruno->id,
            'pattern' => 'transferencia enviada pelo pix fulano de tal',
            'label' => 'Transferência enviada pelo Pix|FULANO DE TAL',
            'action' => BankRuleAction::Personal,
            'title' => 'Aluguel da vaga',
        ]);

        $transaction = $this->createBankTransaction($this->checking, [
            'description' => 'Transferência enviada pelo Pix|FULANO DE TAL',
            'amount' => -300,
        ]);

        $this->assertSame('Fulano De Tal', $transaction->counterparty_name);
        $this->assertSame('Aluguel da vaga', app(BankCatalogService::class)->suggest($transaction)?->choice->title);
    }

    public function test_the_expenses_page_points_to_the_inbox(): void
    {
        $this->pix();

        $this->actingAs($this->bruno)
            ->get(route('expenses.index'))
            ->assertInertia(fn (Assert $page) => $page->where('bankInboxCount', 1));

        $this->actingAs($this->leticia)
            ->get(route('expenses.index'))
            ->assertInertia(fn (Assert $page) => $page->where('bankInboxCount', 0));
    }
}
