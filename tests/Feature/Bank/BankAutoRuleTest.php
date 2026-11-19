<?php

namespace Tests\Feature\Bank;

use App\Enums\BankAccountType;
use App\Enums\BankCatalogType;
use App\Enums\BankReviewStatus;
use App\Enums\BankRuleAction;
use App\Enums\ExpenseScope;
use App\Enums\RecurringKind;
use App\Models\BankAccount;
use App\Models\BankRule;
use App\Models\BankTransaction;
use App\Services\Bank\BankRuleApplier;
use App\Services\Bank\RuleApplication;
use App\Services\RecurringExpenseService;
use App\Support\Month;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\Concerns\CreatesHousehold;
use Tests\Concerns\FakesPluggy;
use Tests\TestCase;

/**
 * Marking a rule "Catalogar sozinho" catalogues, right away, the pending
 * transactions it is for, not only the ones imported later.
 */
class BankAutoRuleTest extends TestCase
{
    use CreatesHousehold, FakesPluggy, RefreshDatabase;

    private BankAccount $checking;

    private BankAccount $card;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-11-19 12:00:00');
        $this->configurePluggy();
        $this->createHousehold();
        $connection = $this->createBankConnection($this->bruno);
        $this->checking = $this->createBankAccount($connection, BankAccountType::Bank, 'acc-checking');
        $this->card = $this->createBankAccount($connection, BankAccountType::Credit, 'acc-card');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    private function pix(string $name = 'FULANO DE TAL', float $amount = -300, string $date = '2026-11-05', ?BankAccount $account = null): BankTransaction
    {
        return $this->createBankTransaction($account ?? $this->checking, [
            'date' => $date.'T00:00:00.000Z',
            'description' => 'Transferência enviada',
            'amount' => $amount,
            'type' => 'DEBIT',
            'operationType' => 'PIX',
            'paymentData' => ['paymentMethod' => 'PIX', 'receiver' => ['name' => $name]],
        ]);
    }

    public function test_saving_an_automatic_rule_catalogues_the_pending_transactions_it_is_for(): void
    {
        $first = $this->pix(date: '2026-11-05');
        $second = $this->pix(amount: -450, date: '2026-11-12');
        $other = $this->pix('CICRANO', date: '2026-11-06');

        $this->actingAs($this->bruno)
            ->post(route('bank.rules.store'), [
                'label' => 'Fulano de Tal',
                'match_mode' => 'equals',
                'action' => 'personal',
                'title' => 'Aluguel da vaga',
                'auto_apply' => '1',
            ])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Regra criada.')
            ->assertInertiaFlash('toast.description', 'Aplicada às pendentes: 3 pendentes verificadas · 2 batem com a regra · 2 catalogadas');

        foreach ([$first, $second] as $transaction) {
            $transaction->refresh();
            $this->assertSame(BankCatalogType::Personal, $transaction->catalog_type);
            $this->assertSame('Aluguel da vaga', $transaction->personalTransaction->title);
        }

        $this->assertTrue($other->fresh()->isPending());
        $this->assertSame(2, BankRule::query()->sole()->hits);
    }

    public function test_a_rule_that_does_not_catalogue_by_itself_leaves_the_inbox_alone(): void
    {
        $pending = $this->pix();

        $this->actingAs($this->bruno)
            ->post(route('bank.rules.store'), ['label' => 'Fulano de Tal', 'match_mode' => 'equals', 'action' => 'personal', 'auto_apply' => '0'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Regra criada.')
            ->assertInertiaFlashMissing('toast.description');

        $this->assertTrue($pending->fresh()->isPending());
    }

    public function test_turning_automatic_on_when_editing_applies_the_rule(): void
    {
        $rule = BankRule::create(['user_id' => $this->bruno->id, 'pattern' => 'fulano de tal', 'label' => 'Fulano de Tal', 'action' => BankRuleAction::Ignore]);
        $pending = $this->pix();

        $this->actingAs($this->bruno)
            ->put(route('bank.rules.update', $rule), ['label' => 'Fulano de Tal', 'match_mode' => 'equals', 'action' => 'ignore', 'auto_apply' => '1'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.description', 'Aplicada às pendentes: 1 pendente verificada · 1 bate com a regra · 1 ignorada');

        $this->assertSame(BankReviewStatus::Ignored, $pending->fresh()->review_status);
    }

    public function test_a_recurring_rule_links_each_payment_to_its_month_and_leaves_the_rest(): void
    {
        $rent = app(RecurringExpenseService::class)->create($this->household, $this->leticia, [
            'scope' => ExpenseScope::Household,
            'kind' => RecurringKind::Fixed,
            'title' => 'Aluguel',
            'notes' => null,
            'amount_cents' => 180_000,
            'due_day' => 5,
            'start_month' => Month::parse('2026-10'),
            'end_month' => null,
            'total_months' => null,
        ]);

        $october = $this->pix(amount: -1800, date: '2026-10-04');
        $november = $this->pix(amount: -1800, date: '2026-11-04');
        // Months before the rent existed: nothing to link to.
        $tooOld = $this->pix(amount: -1800, date: '2026-06-04');

        $this->actingAs($this->bruno)
            ->post(route('bank.rules.store'), [
                'label' => 'Fulano de Tal',
                'match_mode' => 'equals',
                'action' => 'recurring',
                'recurring_expense_id' => $rent->id,
                'auto_apply' => '1',
            ])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.description', 'Aplicada às pendentes: 3 pendentes verificadas · 3 batem com a regra · 2 vinculadas · 1 continua na caixa de entrada');

        $this->assertSame('2026-10', Month::key($october->fresh()->expenseOccurrence->billing_month));
        $this->assertSame('2026-11', Month::key($november->fresh()->expenseOccurrence->billing_month));
        $this->assertTrue($tooOld->fresh()->isPending());
    }

    public function test_a_rule_for_an_exact_value_keeps_its_transactions(): void
    {
        BankRule::create(['user_id' => $this->bruno->id, 'pattern' => 'fulano de tal', 'label' => 'Fulano de Tal', 'action' => BankRuleAction::Household, 'title' => 'Parcela da casa', 'amount_cents' => 150_000]);
        $houseShare = $this->pix(amount: -1500);
        $gym = $this->pix(amount: -120);

        $this->actingAs($this->bruno)
            ->post(route('bank.rules.store'), ['label' => 'Fulano de Tal', 'match_mode' => 'equals', 'action' => 'personal', 'title' => 'Academia', 'auto_apply' => '1'])
            ->assertInertiaFlash('toast.description', 'Aplicada às pendentes: 2 pendentes verificadas · 1 bate com a regra · 1 catalogada');

        $this->assertTrue($houseShare->fresh()->isPending());
        $this->assertSame('Academia', $gym->fresh()->personalTransaction->title);
    }

    public function test_a_charge_with_its_refund_waiting_is_left_to_be_cancelled_out(): void
    {
        $charge = $this->createBankTransaction($this->card, ['description' => 'Padaria Pão Quente', 'amount' => 18.5, 'date' => '2026-11-10T00:00:00.000Z']);
        $this->createBankTransaction($this->card, ['description' => 'Crédito na fatura', 'amount' => -18.5, 'date' => '2026-11-12T00:00:00.000Z']);
        $bread = $this->createBankTransaction($this->card, ['description' => 'Padaria Pão Quente', 'amount' => 22, 'date' => '2026-11-14T00:00:00.000Z']);

        $this->actingAs($this->bruno)
            ->post(route('bank.rules.store'), ['label' => 'Padaria Pão Quente', 'match_mode' => 'equals', 'action' => 'household', 'title' => 'Padaria', 'auto_apply' => '1'])
            ->assertInertiaFlash('toast.description', 'Aplicada às pendentes: 3 pendentes verificadas · 2 batem com a regra · 1 catalogada · 1 continua na caixa de entrada');

        $this->assertTrue($charge->fresh()->isPending());
        $this->assertSame('Padaria', $bread->fresh()->expenseOccurrence->title);
    }

    public function test_cataloguing_with_catalogar_sozinho_applies_the_rule_to_the_other_pending_ones(): void
    {
        $first = $this->pix(date: '2026-11-01');
        $second = $this->pix(date: '2026-11-08');
        $third = $this->pix(date: '2026-11-15');

        $leticiasAccount = $this->createBankAccount($this->createBankConnection($this->leticia, 'item-leticia'));
        $leticias = $this->pix(account: $leticiasAccount);

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $first), ['action' => 'personal', 'title' => 'Aluguel da vaga', 'remember' => '1', 'auto_apply' => '1'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.message', 'Transação catalogada.')
            ->assertInertiaFlash('toast.description', 'Regra automática aplicada às pendentes: 2 pendentes verificadas · 2 batem com a regra · 2 catalogadas');

        foreach ([$first, $second, $third] as $transaction) {
            $this->assertSame('Aluguel da vaga', $transaction->fresh()->personalTransaction->title);
        }

        $this->assertTrue($leticias->fresh()->isPending());
        $this->assertSame(3, BankRule::query()->sole()->hits);
    }

    public function test_cataloguing_without_catalogar_sozinho_only_suggests(): void
    {
        $first = $this->pix(date: '2026-11-01');
        $second = $this->pix(date: '2026-11-08');

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $first), ['action' => 'personal', 'remember' => '1', 'auto_apply' => '0'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlashMissing('toast.description');

        $this->assertTrue($second->fresh()->isPending());
    }

    public function test_only_the_owners_automatic_rules_are_applied(): void
    {
        $pending = $this->pix();
        $applier = app(BankRuleApplier::class);

        $manual = BankRule::create(['user_id' => $this->bruno->id, 'pattern' => 'fulano de tal', 'label' => 'Fulano de Tal', 'action' => BankRuleAction::Ignore]);
        $this->assertSame(0, $applier->apply($manual, $this->bruno)->checked);

        $manual->update(['auto_apply' => true]);
        $this->assertSame(0, $applier->apply($manual, $this->leticia)->checked);
        $this->assertTrue($pending->fresh()->isPending());
    }

    public function test_the_summary_reads_well(): void
    {
        $this->assertSame('Nenhuma transação pendente na caixa de entrada.', (new RuleApplication(BankRuleAction::Personal, 0, 0, 0))->summary());
        $this->assertSame('4 pendentes verificadas · nenhuma bate com a regra', (new RuleApplication(BankRuleAction::Personal, 4, 0, 0))->summary());
        $this->assertSame('4 pendentes verificadas · 2 batem com a regra · nenhuma vinculada · 2 continuam na caixa de entrada', (new RuleApplication(BankRuleAction::Recurring, 4, 2, 0))->summary());
    }
}
