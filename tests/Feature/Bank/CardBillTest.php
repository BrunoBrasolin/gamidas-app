<?php

namespace Tests\Feature\Bank;

use App\Enums\BankAccountType;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankTransaction;
use App\Services\Bank\BankCatalogService;
use App\Services\Bank\CatalogChoice;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\Concerns\CreatesHousehold;
use Tests\Concerns\FakesPluggy;
use Tests\TestCase;

/**
 * Card purchases count on the due date of the bill that pays them, never
 * before: with the bill due on the 10th, a purchase on 12/11 is paid on 10/12.
 */
class CardBillTest extends TestCase
{
    use CreatesHousehold, FakesPluggy, RefreshDatabase;

    private BankConnection $connection;

    private BankAccount $card;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-11-19 12:00:00');
        $this->configurePluggy();
        $this->createHousehold();
        $this->connection = $this->createBankConnection($this->bruno);
        $this->card = $this->createBankAccount($this->connection, BankAccountType::Credit, 'acc-card');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_with_only_the_card_due_date_the_bill_closes_a_week_before(): void
    {
        // The bank's due date is of the bill already closed, before the purchase.
        $this->card->update(['balance_due_date' => '2026-11-10']);

        $this->assertSame('2026-12-10', $this->dueOf('2026-11-12'));
        $this->assertSame('2026-12-10', $this->dueOf('2026-12-03'));
        $this->assertSame('2027-01-10', $this->dueOf('2026-12-04'));
        $this->assertSame('2026-11-10', $this->dueOf('2026-11-03'));
        $this->assertSame('2026-11-10', $this->dueOf('2026-10-04'));
        $this->assertSame('2026-10-10', $this->dueOf('2026-10-03'));
        // Far from the card's due date too.
        $this->assertSame('2026-08-10', $this->dueOf('2026-07-20'));
    }

    public function test_the_card_closing_date_may_be_of_the_open_bill(): void
    {
        $this->card->update(['balance_close_date' => '2026-12-01', 'balance_due_date' => '2026-11-10']);

        $this->assertSame('2026-11-10', $this->dueOf('2026-11-01'));
        $this->assertSame('2026-12-10', $this->dueOf('2026-11-02'));
        $this->assertSame('2027-01-10', $this->dueOf('2026-12-02'));
    }

    public function test_bills_without_closing_date_close_a_week_before_they_are_due(): void
    {
        $this->card->bills()->create(['pluggy_id' => 'bill-oct', 'due_date' => '2026-10-10', 'total_cents' => 100]);

        $this->assertSame('2026-10-10', $this->dueOf('2026-10-03'));
        $this->assertSame('2026-11-10', $this->dueOf('2026-10-05'));
        $this->assertSame('2026-12-10', $this->dueOf('2026-11-12'));
    }

    public function test_bills_the_bank_never_sent_are_projected_from_the_ones_it_did(): void
    {
        $this->card->bills()->create(['pluggy_id' => 'bill-sep', 'closing_date' => '2026-09-01', 'due_date' => '2026-09-10', 'total_cents' => 100]);
        $this->card->bills()->create(['pluggy_id' => 'bill-nov', 'closing_date' => '2026-11-01', 'due_date' => '2026-11-10', 'total_cents' => 100]);

        $this->assertSame('2026-10-10', $this->dueOf('2026-09-15'));
        $this->assertSame('2026-11-10', $this->dueOf('2026-10-02'));
        $this->assertSame('2026-09-10', $this->dueOf('2026-08-20'));
        $this->assertSame('2026-08-10', $this->dueOf('2026-07-20'));
        $this->assertSame('2026-12-10', $this->dueOf('2026-11-12'));
    }

    public function test_installments_are_paid_one_bill_after_the_other(): void
    {
        $this->card->update(['balance_due_date' => '2026-12-10']);

        // Third installment of a purchase on 15/09: the first one was paid on 10/10.
        $installment = $this->purchase('2026-09-15', ['installmentNumber' => 3, 'totalInstallments' => 10]);
        $this->assertSame('2026-12-10', $this->referenceDate($installment));

        // The bank's date may be when it was charged: the purchase date counts.
        $installment = $this->purchase('2026-11-20', ['installmentNumber' => 3, 'totalInstallments' => 10, 'purchaseDate' => '2026-09-15T00:00:00.000Z']);
        $this->assertSame('2026-12-10', $this->referenceDate($installment));
    }

    public function test_a_purchase_in_a_bill_the_bank_sent_is_due_with_it(): void
    {
        $this->card->update(['balance_due_date' => '2026-12-10']);
        $this->card->bills()->create(['pluggy_id' => 'bill-jan', 'due_date' => '2027-01-10', 'total_cents' => 100]);

        $purchase = $this->purchase('2026-11-12', ['billId' => 'bill-jan']);
        $purchase->update(['bank_bill_id' => $this->card->bills()->where('pluggy_id', 'bill-jan')->value('id')]);

        $this->assertSame('2027-01-10', $this->referenceDate($purchase));
    }

    public function test_without_due_dates_the_purchase_counts_on_its_day(): void
    {
        $this->assertSame('2026-11-12', $this->dueOf('2026-11-12'));
    }

    public function test_the_household_expense_of_a_purchase_is_due_with_its_bill(): void
    {
        $this->card->update(['balance_due_date' => '2026-11-10']);
        $purchase = $this->purchase('2026-11-12');

        app(BankCatalogService::class)->catalog($purchase, $this->bruno, new CatalogChoice('household'));

        $occurrence = $purchase->fresh()->expenseOccurrence;
        $this->assertSame('2026-12-10', $occurrence->due_date->toDateString());
        $this->assertSame('2026-12-01', $occurrence->billing_month->toDateString());
    }

    public function test_pending_expenses_left_before_their_bill_move_forward_to_it(): void
    {
        // Catalogued before the card had a due date: due on the purchase day.
        $early = $this->catalogued('2026-11-12');
        $installment = $this->catalogued('2026-09-15', '2026-09-15', ['installmentNumber' => 3, 'totalInstallments' => 10]);
        $paid = $this->catalogued('2026-11-13');
        app(PaymentService::class)->confirm($paid->expenseOccurrence, $this->leticia);
        $later = $this->catalogued('2026-11-14', '2027-02-10');
        $right = $this->catalogued('2026-11-15', '2026-12-10');

        $this->card->update(['balance_due_date' => '2026-11-10']);

        $this->assertSame(2, app(BankCatalogService::class)->moveCardExpensesToTheirBill());

        $this->assertSame('2026-12-10', $early->expenseOccurrence->fresh()->due_date->toDateString());
        $this->assertSame('2026-12-01', $early->expenseOccurrence->fresh()->billing_month->toDateString());
        $this->assertSame('2026-12-10', $installment->expenseOccurrence->fresh()->due_date->toDateString());
        $this->assertSame('2026-11-13', $paid->expenseOccurrence->fresh()->due_date->toDateString());
        $this->assertSame('2027-02-10', $later->expenseOccurrence->fresh()->due_date->toDateString());
        $this->assertSame('2026-12-10', $right->expenseOccurrence->fresh()->due_date->toDateString());

        $log = AuditLog::query()->where('action', 'bank.moved_to_bill')->where('auditable_id', $early->expense_occurrence_id)->sole();
        $this->assertNull($log->user_id);
        $this->assertSame('2026-11-12', $log->old_values['due_date']);
        $this->assertSame('2026-12-10', $log->new_values['due_date']);
    }

    private function dueOf(string $date): string
    {
        return $this->referenceDate($this->purchase($date));
    }

    private function referenceDate(BankTransaction $transaction): string
    {
        // A new service each time: the card's bills are loaded once per instance.
        return app(BankCatalogService::class)->referenceDate($transaction->fresh())->toDateString();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function purchase(string $date, array $metadata = []): BankTransaction
    {
        return $this->createBankTransaction($this->card, [
            'date' => $date.'T00:00:00.000Z',
            'description' => 'Loja Exemplo',
            'amount' => 80,
            'creditCardMetadata' => $metadata,
        ]);
    }

    /**
     * A purchase catalogued as a household expense due on the given day.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function catalogued(string $date, ?string $due = null, array $metadata = []): BankTransaction
    {
        $purchase = $this->purchase($date, $metadata);

        app(BankCatalogService::class)->catalog($purchase, $this->bruno, new CatalogChoice(
            'household',
            date: $this->day($due ?? $date),
            remember: false,
        ));

        return $purchase->fresh();
    }
}
