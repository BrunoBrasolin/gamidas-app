<?php

namespace Tests\Feature\Bank;

use App\Enums\BankAccountType;
use App\Enums\BankCatalogType;
use App\Enums\BankRuleAction;
use App\Enums\ExpenseScope;
use App\Enums\OccurrenceStatus;
use App\Enums\PaymentEventType;
use App\Models\BankAccount;
use App\Models\BankRule;
use App\Models\BankSettlementItem;
use App\Models\BankTransaction;
use App\Models\ExpenseOccurrence;
use App\Models\PaymentEvent;
use App\Models\PersonalTransaction;
use App\Models\User;
use App\Services\Bank\BankCatalogService;
use App\Services\Bank\CatalogChoice;
use App\Services\ExpenseService;
use App\Services\HouseholdService;
use App\Services\PaymentService;
use App\Support\Month;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\Concerns\FakesPluggy;
use Tests\TestCase;

/**
 * The house settlement ("Acerto da casa"): one PIX between the members pays
 * their shares of several household expenses.
 */
class BankSettlementTest extends TestCase
{
    use CreatesHousehold, FakesPluggy, RefreshDatabase;

    private BankAccount $brunoChecking;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-11-19 12:00:00');
        $this->configurePluggy();
        // Bruno earns 80%, Letícia 20%.
        $this->createHousehold();
        $this->brunoChecking = $this->createBankAccount($this->createBankConnection($this->bruno), BankAccountType::Bank, 'acc-bruno');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    private function expense(User $creator, string $title, int $cents, string $due): ExpenseOccurrence
    {
        return app(ExpenseService::class)->create($this->household, $creator, [
            'scope' => ExpenseScope::Household,
            'title' => $title,
            'notes' => null,
            'amount_cents' => $cents,
            'due_date' => $this->day($due),
        ]);
    }

    /**
     * October bills Bruno registered: Letícia owes 20% of each, R$ 484,69.
     *
     * @return list<ExpenseOccurrence>
     */
    private function brunosOctoberBills(): array
    {
        return [
            $this->expense($this->bruno, 'Aluguel', 150_000, '2026-10-05'),  // Letícia: 300,00
            $this->expense($this->bruno, 'Internet', 80_000, '2026-10-10'),  // Letícia: 160,00
            $this->expense($this->bruno, 'Água', 12_345, '2026-10-15'),      // Letícia: 24,69
        ];
    }

    private function pixFromLeticia(float $amount = 484.69, string $date = '2026-11-03', ?BankAccount $account = null): BankTransaction
    {
        return $this->createBankTransaction($account ?? $this->brunoChecking, [
            'date' => $date.'T00:00:00.000Z',
            'description' => 'Transferência Recebida|Leticia Souza',
            'amount' => $amount,
            'type' => 'CREDIT',
            'operationType' => 'PIX',
            'paymentData' => ['paymentMethod' => 'PIX', 'payer' => ['name' => 'Leticia Souza']],
        ]);
    }

    private function pixToBruno(BankAccount $account, float $amount = -484.69): BankTransaction
    {
        return $this->createBankTransaction($account, [
            'date' => '2026-11-03T00:00:00.000Z',
            'description' => 'Transferência enviada|Bruno Brasolin',
            'amount' => $amount,
            'type' => 'DEBIT',
            'operationType' => 'PIX',
            'paymentData' => ['paymentMethod' => 'PIX', 'receiver' => ['name' => 'Bruno Brasolin']],
        ]);
    }

    /**
     * @param  list<ExpenseOccurrence>  $occurrences
     * @return list<int>
     */
    private function ids(array $occurrences): array
    {
        return array_map(fn (ExpenseOccurrence $occurrence) => $occurrence->id, $occurrences);
    }

    public function test_the_inbox_suggests_the_month_whose_shares_add_up_to_the_pix(): void
    {
        $bills = $this->brunosOctoberBills();
        $this->expense($this->bruno, 'Condomínio', 50_000, '2026-11-10');
        $pix = $this->pixFromLeticia();

        $this->actingAs($this->bruno)
            ->get(route('bank.inbox'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('settleable', 4)
                ->where('settleable.0.id', $bills[0]->id)
                ->where('settleable.0.payer_user_id', $this->leticia->id)
                ->where('settleable.0.share_cents', 30_000)
                ->where('transactions.data.0.id', $pix->id)
                ->where('transactions.data.0.settlement_ids', $this->ids($bills))
                ->where('transactions.data.0.suggestion.action', 'settlement')
                ->where('transactions.data.0.suggestion.occurrence_ids', $this->ids($bills))
                ->where('transactions.data.0.suggestion.label', 'Acerto da casa: outubro/2026 (3 despesas)'));
    }

    public function test_settling_pays_each_expense_in_the_name_of_who_owed_the_share(): void
    {
        $bills = $this->brunosOctoberBills();
        $november = $this->expense($this->bruno, 'Condomínio', 50_000, '2026-11-10');
        $pix = $this->pixFromLeticia();

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $pix), [
                'action' => 'settlement',
                'occurrence_ids' => $this->ids($bills),
                'remember' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $pix->refresh();
        $this->assertSame(BankCatalogType::Settlement, $pix->catalog_type);
        $this->assertTrue($pix->isCatalogued());
        $this->assertNull($pix->expense_occurrence_id);

        foreach ($bills as $bill) {
            $bill->refresh();
            $this->assertSame(OccurrenceStatus::Paid, $bill->status);
            // Bruno registered it and settled it, but Letícia paid her share.
            $this->assertSame($this->leticia->id, $bill->paid_by_user_id);
            $this->assertSame('2026-11-03', $bill->paid_at->toDateString());

            $event = PaymentEvent::query()->where('expense_occurrence_id', $bill->id)->sole();
            $this->assertSame(PaymentEventType::Paid, $event->type);
            $this->assertSame($this->bruno->id, $event->user_id);
            $this->assertSame('Acerto da casa: PIX de 03/11/2026 (R$ 484,69)', $event->reason);
        }

        $this->assertSame([30_000, 16_000, 2_469], BankSettlementItem::query()->orderBy('id')->pluck('amount_cents')->all());
        $this->assertSame(OccurrenceStatus::Pending, $november->fresh()->status);
        // Not income: Letícia's share coming back is already left out of Bruno's expenses.
        $this->assertSame(0, PersonalTransaction::query()->count());

        $rule = BankRule::query()->sole();
        $this->assertSame(BankRuleAction::Settlement, $rule->action);
        $this->assertNull($rule->title);

        $this->actingAs($this->leticia)
            ->get(route('expenses.index', ['month' => '2026-10']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('occurrences.data.0.id', $bills[0]->id)
                ->where('occurrences.data.0.payment.paid_label', 'Pago pela Letícia')
                ->where('occurrences.data.0.payment.can_reopen', false)
                ->where('occurrences.data.0.settlement_proof.member', 'Bruno')
                ->where('occurrences.data.0.settlement_proof.amount_cents', 48_469)
                ->where('occurrences.data.0.settlement_proof.date', '2026-11-03'));

        $this->actingAs($this->bruno)
            ->get(route('bank.inbox', ['status' => 'catalogued']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('transactions.data.0.settlement.count', 3)
                ->where('transactions.data.0.settlement.months', ['2026-10']));
    }

    public function test_a_net_transfer_also_pays_the_bills_the_sender_registered(): void
    {
        $bills = $this->brunosOctoberBills();
        // Letícia registered this one: Bruno owes her R$ 80,00 of it.
        $hers = $this->expense($this->leticia, 'Gás', 10_000, '2026-10-20');
        // 484,69 − 80,00
        $pix = $this->pixFromLeticia(404.69);

        $suggestion = app(BankCatalogService::class)->suggest($pix->fresh());
        $this->assertSame('settlement', $suggestion?->choice->action);
        $this->assertSame([...$this->ids($bills), $hers->id], $suggestion->choice->occurrenceIds);

        app(BankCatalogService::class)->confirmSuggestion($pix, $this->bruno);

        $this->assertSame($this->bruno->id, $hers->fresh()->paid_by_user_id);
        $this->assertSame($this->leticia->id, $bills[0]->fresh()->paid_by_user_id);
        $this->assertSame(8_000, BankSettlementItem::query()->where('expense_occurrence_id', $hers->id)->value('amount_cents'));
    }

    public function test_undo_reopens_the_expenses_of_the_settlement(): void
    {
        $bills = $this->brunosOctoberBills();
        $pix = $this->pixFromLeticia();
        app(BankCatalogService::class)->catalog($pix, $this->bruno, new CatalogChoice('settlement', occurrenceIds: $this->ids($bills)));

        // Letícia cannot reopen one of them on its own.
        $this->actingAs($this->leticia)
            ->delete(route('expenses.payment.destroy', $bills[0]), ['reason' => 'Engano'])
            ->assertForbidden();

        $this->actingAs($this->bruno)
            ->delete(route('bank.transactions.reopen', $pix))
            ->assertSessionHasNoErrors();

        $this->assertTrue($pix->fresh()->isPending());
        $this->assertNull($pix->fresh()->catalog_type);
        $this->assertSame(0, BankSettlementItem::query()->count());

        foreach ($bills as $bill) {
            $bill->refresh();
            $this->assertSame(OccurrenceStatus::Pending, $bill->status);
            $this->assertNull($bill->paid_by_user_id);
            $this->assertSame('Acerto da casa desfeito na caixa de entrada.', PaymentEvent::query()
                ->where('expense_occurrence_id', $bill->id)
                ->where('type', PaymentEventType::Reopened)
                ->value('reason'));
        }
    }

    public function test_settling_is_all_or_nothing(): void
    {
        [$rent, $internet] = $this->brunosOctoberBills();
        app(PaymentService::class)->confirm($internet, $this->leticia);
        $personal = $this->createExpense($this->bruno, 9_000, Month::parse('2026-10'), ExpenseScope::Personal);
        $pix = $this->pixFromLeticia();

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $pix), ['action' => 'settlement', 'occurrence_ids' => []])
            ->assertSessionHasErrors('occurrence_ids');

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $pix), ['action' => 'settlement', 'occurrence_ids' => [$rent->id, $internet->id]])
            ->assertSessionHasErrors(['occurrence_ids' => '“Internet” já foi paga.']);

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $pix), ['action' => 'settlement', 'occurrence_ids' => [$rent->id, $personal->id]])
            ->assertSessionHasErrors('occurrence_ids');

        $this->assertSame(OccurrenceStatus::Pending, $rent->fresh()->status);
        $this->assertTrue($pix->fresh()->isPending());
        $this->assertSame(0, BankSettlementItem::query()->count());
    }

    public function test_the_same_pix_seen_in_the_other_bank_links_without_paying_again(): void
    {
        $bills = $this->brunosOctoberBills();
        $pix = $this->pixFromLeticia();
        app(BankCatalogService::class)->catalog($pix, $this->bruno, new CatalogChoice('settlement', occurrenceIds: $this->ids($bills)));

        $leticiaChecking = $this->createBankAccount($this->createBankConnection($this->leticia, 'item-leticia'), BankAccountType::Bank, 'acc-leticia');
        $sent = $this->pixToBruno($leticiaChecking);

        $this->actingAs($this->leticia)
            ->get(route('bank.inbox'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('settleable', 3)
                ->where('settleable.0.settled_by_partner', true)
                ->where('transactions.data.0.settlement_ids', $this->ids($bills)));

        $this->actingAs($this->leticia)
            ->post(route('bank.transactions.catalog', $sent), ['action' => 'settlement', 'occurrence_ids' => $this->ids($bills)])
            ->assertSessionHasNoErrors();

        $this->assertSame(6, BankSettlementItem::query()->count());
        $this->assertSame(1, PaymentEvent::query()->where('expense_occurrence_id', $bills[0]->id)->count());

        // Undoing one side keeps them paid by the other.
        app(BankCatalogService::class)->reopen($pix, $this->bruno);
        $this->assertSame(OccurrenceStatus::Paid, $bills[0]->fresh()->status);

        app(BankCatalogService::class)->reopen($sent, $this->leticia);
        $this->assertSame(OccurrenceStatus::Pending, $bills[0]->fresh()->status);
    }

    public function test_a_settlement_rule_applies_automatically_when_a_month_adds_up(): void
    {
        $bills = $this->brunosOctoberBills();
        $pix = $this->pixFromLeticia();
        app(BankCatalogService::class)->catalog($pix, $this->bruno, new CatalogChoice('settlement', occurrenceIds: $this->ids($bills), autoApply: true));

        $november = [
            $this->expense($this->bruno, 'Aluguel', 50_000, '2026-11-05'),   // Letícia: 100,00
            $this->expense($this->bruno, 'Internet', 25_000, '2026-11-10'),  // Letícia: 50,00
        ];

        $next = $this->pixFromLeticia(150.00, '2026-12-03');
        app(BankCatalogService::class)->onImported($next);

        $this->assertSame(BankCatalogType::Settlement, $next->fresh()->catalog_type);
        $this->assertSame(OccurrenceStatus::Paid, $november[0]->fresh()->status);
        $this->assertSame(OccurrenceStatus::Paid, $november[1]->fresh()->status);

        // A value no month adds up to stays in the inbox, suggested as a
        // settlement whose expenses are picked by hand.
        $odd = $this->pixFromLeticia(99.99, '2026-12-04');
        app(BankCatalogService::class)->onImported($odd);

        $this->assertTrue($odd->fresh()->isPending());
        $suggestion = app(BankCatalogService::class)->suggest($odd->fresh());
        $this->assertSame('settlement', $suggestion?->choice->action);
        $this->assertSame([], $suggestion->choice->occurrenceIds);
    }

    public function test_the_settlement_needs_a_household_of_two(): void
    {
        $bills = $this->brunosOctoberBills();
        app(HouseholdService::class)->addMember($this->household, 'Carla Terceira', 'carla@example.test', 'password', 'pela', 100_000);
        $pix = $this->pixFromLeticia();

        $this->actingAs($this->bruno)
            ->get(route('bank.inbox'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('settleable', [])
                ->where('transactions.data.0.settlement_ids', []));

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $pix), ['action' => 'settlement', 'occurrence_ids' => $this->ids($bills)])
            ->assertSessionHasErrors('action');

        $this->assertSame(OccurrenceStatus::Pending, $bills[0]->fresh()->status);
    }
}
