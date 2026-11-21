<?php

namespace Tests\Feature\Bank;

use App\Enums\BankAccountType;
use App\Enums\BankCatalogType;
use App\Enums\BankChannel;
use App\Enums\BankDirection;
use App\Enums\BankReviewStatus;
use App\Models\BankAccount;
use App\Models\BankBill;
use App\Models\BankConnection;
use App\Models\BankInvestment;
use App\Models\BankInvestmentSnapshot;
use App\Models\BankTransaction;
use App\Services\Bank\BankCatalogService;
use App\Services\Bank\BankSyncService;
use App\Services\Bank\CatalogChoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\Concerns\CreatesHousehold;
use Tests\Concerns\FakesPluggy;
use Tests\TestCase;

class BankSyncTest extends TestCase
{
    use CreatesHousehold, FakesPluggy, RefreshDatabase;

    private BankConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-11-19 12:00:00');
        $this->configurePluggy();
        $this->createHousehold();
        $this->pluggyItem();
        $this->fakePluggy();
        $this->connection = $this->createBankConnection($this->bruno, 'item-nubank', ['import_from' => '2026-10-20']);
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    private function sync(array $updatedIds = []): ?int
    {
        return app(BankSyncService::class)->sync($this->connection->fresh(), $updatedIds);
    }

    public function test_each_sync_records_the_days_investment_balance(): void
    {
        $this->pluggyInvestments['item-nubank'] = [[
            'id' => 'inv-1', 'name' => 'Caixinha Viagem', 'type' => 'FIXED_INCOME', 'subtype' => 'CDB',
            'balance' => 2500.75, 'amountOriginal' => 2400, 'amountProfit' => 100.75, 'amountWithdrawal' => 2480.6,
        ], [
            'id' => 'inv-2', 'name' => 'Fundo sem aplicado', 'type' => 'MUTUAL_FUND',
            'balance' => 1000, 'amountProfit' => 50,
        ]];

        $this->sync();

        $caixinha = BankInvestment::query()->firstWhere('pluggy_id', 'inv-1');
        $this->assertSame(10075, $caixinha->profit_cents);
        $this->assertSame(248060, $caixinha->withdrawal_cents);
        // Without the invested amount, it is the balance minus the profit.
        $this->assertSame(95000, BankInvestment::query()->firstWhere('pluggy_id', 'inv-2')->investedCents());

        // Later the same day: the day's row is updated, not duplicated.
        $this->pluggyInvestments['item-nubank'][0]['balance'] = 2510;
        $this->sync();
        $this->assertSame(2, BankInvestmentSnapshot::query()->count());
        $this->assertSame(251000, BankInvestmentSnapshot::query()->firstWhere('pluggy_id', 'inv-1')->balance_cents);

        // The next day adds a row; a withdrawn position keeps its history.
        Date::setTestNow('2026-11-20 12:00:00');
        unset($this->pluggyInvestments['item-nubank'][1]);
        $this->sync();
        $this->assertSame(3, BankInvestmentSnapshot::query()->count());
        $this->assertSame(1, BankInvestment::query()->count());
        $this->assertSame(['2026-11-19', '2026-11-20'], BankInvestmentSnapshot::query()->where('pluggy_id', 'inv-1')->orderBy('date')->get()->map(fn ($row) => $row->date->toDateString())->all());

        // Disconnecting deletes the history with everything else.
        $this->connection->delete();
        $this->assertSame(0, BankInvestmentSnapshot::query()->count());
    }

    public function test_the_first_sync_imports_accounts_bills_investments_and_transactions(): void
    {
        $this->pluggyBills['acc-card'] = [[
            'id' => 'bill-oct', 'dueDate' => '2026-11-10T00:00:00.000Z', 'billClosingDate' => '2026-11-03T00:00:00.000Z',
            'totalAmount' => 950.4, 'minimumPaymentAmount' => 142.56,
            'financeCharges' => [['type' => 'IOF', 'amount' => 3.21]],
        ]];
        $this->pluggyInvestments['item-nubank'] = [[
            'id' => 'inv-1', 'name' => 'Caixinha Viagem', 'type' => 'FIXED_INCOME', 'subtype' => 'CDB',
            'balance' => 2500.75, 'amountOriginal' => 2400, 'rate' => 100, 'rateType' => 'CDI', 'status' => 'ACTIVE',
        ]];
        $this->pluggyTransaction('acc-checking', 'tx-pix', [
            'description' => 'Transferência enviada pelo Pix - FULANO DE TAL - •••.456.789-••',
            'amount' => -1800, 'type' => 'DEBIT', 'operationType' => 'PIX',
        ]);
        $this->pluggyTransaction('acc-card', 'tx-card', ['description' => 'Padaria Pão Quente', 'amount' => 23.9]);
        $this->pluggyTransaction('acc-card', 'tx-old', ['description' => 'Antes da conexão', 'date' => '2026-09-01T00:00:00.000Z']);

        $this->assertSame(2, $this->sync());

        $connection = $this->connection->fresh();
        $this->assertSame('UPDATED', $connection->status);
        $this->assertSame('Nubank', $connection->connector_name);
        $this->assertSame('#820AD1', $connection->connector_color);
        $this->assertNotNull($connection->synced_at);
        $this->assertNull($connection->sync_requested_at);

        $checking = BankAccount::query()->firstWhere('pluggy_id', 'acc-checking');
        $card = BankAccount::query()->firstWhere('pluggy_id', 'acc-card');
        $this->assertSame(152035, $checking->balance_cents);
        $this->assertSame(BankAccountType::Credit, $card->type);
        $this->assertSame(500000, $card->credit_limit_cents);
        $this->assertSame('2026-12-10', $card->balance_due_date->toDateString());

        $bill = BankBill::query()->sole();
        $this->assertSame(95040, $bill->total_cents);
        $this->assertSame(321, $bill->finance_charges_cents);
        $this->assertSame('2026-11-03', $bill->closing_date->toDateString());

        $investment = BankInvestment::query()->sole();
        $this->assertSame(250075, $investment->balance_cents);
        $this->assertSame('100% CDI', $investment->rate);

        $pix = BankTransaction::query()->firstWhere('pluggy_id', 'tx-pix');
        $this->assertSame($this->bruno->id, $pix->user_id);
        $this->assertSame(180000, $pix->amount_cents);
        $this->assertSame(BankDirection::Out, $pix->direction);
        $this->assertSame(BankChannel::Pix, $pix->channel);
        $this->assertSame('Fulano De Tal', $pix->counterparty_name);
        $this->assertSame(BankReviewStatus::Pending, $pix->review_status);

        $this->assertDatabaseMissing('bank_transactions', ['pluggy_id' => 'tx-old']);
    }

    public function test_syncing_again_never_duplicates_and_refreshes_what_the_bank_changed(): void
    {
        $this->pluggyTransaction('acc-card', 'tx-card', ['amount' => 23.9, 'status' => 'PENDING']);
        $this->sync();

        BankTransaction::query()->firstWhere('pluggy_id', 'tx-card')->update(['review_status' => BankReviewStatus::Ignored]);
        $this->pluggyTransaction('acc-card', 'tx-card', ['amount' => 25.9, 'status' => 'POSTED', 'creditCardMetadata' => ['billId' => 'bill-nov']]);

        $this->assertSame(0, $this->sync());

        $transaction = BankTransaction::query()->sole();
        $this->assertSame(2590, $transaction->amount_cents);
        $this->assertSame('POSTED', $transaction->bank_status);
        $this->assertSame('bill-nov', $transaction->pluggy_bill_id);
        // What the owner decided is kept.
        $this->assertSame(BankReviewStatus::Ignored, $transaction->review_status);
    }

    public function test_installments_created_after_the_connection_are_imported_even_with_old_dates(): void
    {
        $this->sync();
        Date::setTestNow('2026-12-05 12:00:00');

        $this->pluggyTransaction('acc-card', 'tx-installment-3', [
            'description' => 'Loja de Móveis',
            'date' => '2026-09-15T00:00:00.000Z',
            'createdAt' => '2026-12-04T08:00:00.000Z',
            'amount' => 300,
            'creditCardMetadata' => ['installmentNumber' => 3, 'totalInstallments' => 10],
        ]);

        $this->assertSame(1, $this->sync());
        $this->assertSame(3, BankTransaction::query()->sole()->installment_number);
    }

    public function test_updated_ids_from_webhooks_are_fetched_even_outside_the_window(): void
    {
        $this->pluggyTransaction('acc-card', 'tx-1', ['date' => '2026-10-25T00:00:00.000Z', 'status' => 'PENDING']);
        $this->sync();

        Date::setTestNow('2027-01-20 12:00:00');
        $this->pluggyTransaction('acc-card', 'tx-1', ['date' => '2026-10-25T00:00:00.000Z', 'status' => 'POSTED', 'createdAt' => '2026-10-25T10:00:00.000Z']);

        $this->sync(['acc-card' => ['tx-1']]);

        $this->assertSame('POSTED', BankTransaction::query()->sole()->bank_status);
    }

    public function test_card_bill_payments_are_ignored_automatically_on_both_sides(): void
    {
        $this->pluggyTransaction('acc-card', 'tx-payment', ['description' => 'Pagamento recebido', 'amount' => -950.4, 'type' => 'CREDIT']);
        $this->pluggyTransaction('acc-checking', 'tx-bill', ['description' => 'Pagamento de fatura', 'amount' => -950.4, 'type' => 'DEBIT']);

        $this->sync();

        $this->assertSame(2, BankTransaction::query()->where('review_status', BankReviewStatus::Ignored)->where('ignore_reason', BankTransaction::IGNORED_BILL_PAYMENT)->count());
        $this->assertSame(0, app(BankCatalogService::class)->pendingOf($this->bruno)->count());
    }

    public function test_card_purchases_open_finance_calls_payments_reach_the_inbox(): void
    {
        $this->pluggyTransaction('acc-card', 'tx-purchase', ['description' => 'Mercado Bom Preço', 'amount' => 152.3, 'operationType' => 'PAGAMENTO']);
        $this->pluggyTransaction('acc-card', 'tx-payment', ['description' => 'Pagamento recebido', 'amount' => -950.4, 'type' => 'CREDIT', 'operationType' => 'PAGAMENTO_FATURA']);

        $this->sync();

        $purchase = BankTransaction::query()->firstWhere('pluggy_id', 'tx-purchase');
        $this->assertSame(BankChannel::Card, $purchase->channel);
        $this->assertSame(BankReviewStatus::Pending, $purchase->review_status);

        $payment = BankTransaction::query()->firstWhere('pluggy_id', 'tx-payment');
        $this->assertSame(BankChannel::BillPayment, $payment->channel);
        $this->assertSame(BankReviewStatus::Ignored, $payment->review_status);
    }

    public function test_card_purchases_ignored_as_bill_payments_go_back_to_the_inbox(): void
    {
        $this->pluggyTransaction('acc-card', 'tx-purchase', ['description' => 'Mercado Bom Preço', 'amount' => 152.3, 'operationType' => 'PAGAMENTO']);
        $this->pluggyTransaction('acc-card', 'tx-iof', ['description' => 'IOF de compra internacional', 'amount' => 3.21, 'operationType' => 'PAGAMENTO']);
        $this->pluggyTransaction('acc-card', 'tx-refreshed', ['description' => 'Farmácia Central', 'amount' => 42, 'operationType' => 'PAGAMENTO']);
        $this->pluggyTransaction('acc-card', 'tx-decided', ['description' => 'Loja de Presentes', 'amount' => 80, 'operationType' => 'PAGAMENTO']);
        $this->pluggyTransaction('acc-card', 'tx-payment', ['description' => 'Pagamento recebido', 'amount' => -950.4, 'type' => 'CREDIT', 'operationType' => 'PAGAMENTO_FATURA']);
        $this->pluggyTransaction('acc-checking', 'tx-bill', ['description' => 'Pagamento de fatura', 'amount' => -950.4, 'type' => 'DEBIT']);
        $this->sync();

        // As the previous classifier left them; a sync with the fixed one
        // already restored the channel of a recent one, still ignored.
        $wrong = ['channel' => BankChannel::BillPayment, 'review_status' => BankReviewStatus::Ignored, 'reviewed_at' => Date::now()];
        BankTransaction::query()->whereIn('pluggy_id', ['tx-purchase', 'tx-iof'])->update([...$wrong, 'ignore_reason' => BankTransaction::IGNORED_BILL_PAYMENT]);
        BankTransaction::query()->where('pluggy_id', 'tx-refreshed')->update([...$wrong, 'channel' => BankChannel::Card, 'ignore_reason' => BankTransaction::IGNORED_BILL_PAYMENT]);
        BankTransaction::query()->where('pluggy_id', 'tx-decided')->update([...$wrong, 'ignore_reason' => BankTransaction::IGNORED_BY_USER]);

        (require database_path('migrations/2026_11_19_000004_reopen_card_purchases_ignored_as_bill_payments.php'))->up();

        $purchase = BankTransaction::query()->firstWhere('pluggy_id', 'tx-purchase');
        $this->assertSame(BankChannel::Card, $purchase->channel);
        $this->assertSame(BankReviewStatus::Pending, $purchase->review_status);
        $this->assertNull($purchase->ignore_reason);
        $this->assertNull($purchase->reviewed_at);

        $fee = BankTransaction::query()->firstWhere('pluggy_id', 'tx-iof');
        $this->assertSame(BankChannel::Fee, $fee->channel);
        $this->assertSame(BankReviewStatus::Pending, $fee->review_status);

        $refreshed = BankTransaction::query()->firstWhere('pluggy_id', 'tx-refreshed');
        $this->assertSame(BankChannel::Card, $refreshed->channel);
        $this->assertSame(BankReviewStatus::Pending, $refreshed->review_status);
        $this->assertNull($refreshed->ignore_reason);

        $decided = BankTransaction::query()->firstWhere('pluggy_id', 'tx-decided');
        $this->assertSame(BankChannel::Card, $decided->channel);
        $this->assertSame(BankReviewStatus::Ignored, $decided->review_status);
        $this->assertSame(BankTransaction::IGNORED_BY_USER, $decided->ignore_reason);

        foreach (['tx-payment', 'tx-bill'] as $billPayment) {
            $transaction = BankTransaction::query()->firstWhere('pluggy_id', $billPayment);
            $this->assertSame(BankChannel::BillPayment, $transaction->channel);
            $this->assertSame(BankReviewStatus::Ignored, $transaction->review_status);
        }

        $this->assertSame(3, app(BankCatalogService::class)->pendingOf($this->bruno)->count());
    }

    public function test_a_pending_purchase_the_bank_recreates_keeps_its_catalogue(): void
    {
        $this->pluggyTransaction('acc-card', 'tx-old-id', ['description' => 'Restaurante Sabor', 'amount' => 89.9, 'status' => 'PENDING']);
        $this->sync();

        $old = BankTransaction::query()->sole();
        app(BankCatalogService::class)->catalog($old, $this->bruno, new CatalogChoice('household', title: 'Jantar'));
        $expenseId = $old->fresh()->expense_occurrence_id;

        // Near the bill closing the bank deletes it and creates it again.
        Date::setTestNow('2026-11-20 12:00:00');
        $this->removePluggyTransaction('acc-card', 'tx-old-id');
        $this->pluggyTransaction('acc-card', 'tx-new-id', [
            'description' => 'Restaurante Sabor', 'amount' => 89.9, 'status' => 'POSTED', 'createdAt' => '2026-11-20T08:00:00.000Z',
        ]);

        $this->sync();

        $new = BankTransaction::query()->sole();
        $this->assertSame('tx-new-id', $new->pluggy_id);
        $this->assertSame(BankReviewStatus::Catalogued, $new->review_status);
        $this->assertSame(BankCatalogType::Household, $new->catalog_type);
        $this->assertSame($expenseId, $new->expense_occurrence_id);
        $this->assertNull($new->removed_at);
    }

    public function test_a_vanished_pending_purchase_is_flagged_and_comes_back_if_listed_again(): void
    {
        $this->pluggyTransaction('acc-card', 'tx-1', ['status' => 'PENDING']);
        $this->sync();

        $this->removePluggyTransaction('acc-card', 'tx-1');
        $this->sync();
        $this->assertNotNull(BankTransaction::query()->sole()->removed_at);

        $this->pluggyTransaction('acc-card', 'tx-1', ['status' => 'PENDING']);
        $this->sync();
        $this->assertNull(BankTransaction::query()->sole()->removed_at);
    }

    public function test_item_errors_are_recorded(): void
    {
        $this->pluggyItem('item-nubank', [
            'status' => 'LOGIN_ERROR',
            'executionStatus' => 'USER_AUTHORIZATION_EXPIRED',
            'error' => ['code' => 'USER_AUTHORIZATION_EXPIRED', 'message' => 'A autorização expirou.'],
        ]);

        $this->sync();

        $connection = $this->connection->fresh();
        $this->assertTrue($connection->needsReconnect());
        $this->assertSame('A autorização expirou.', $connection->error_message);
    }

    public function test_the_scheduled_command_syncs_only_pending_or_stale_connections(): void
    {
        $this->sync();
        $this->pluggyTransaction('acc-checking', 'tx-1');

        $this->artisan('bank:sync')->assertSuccessful();
        $this->assertSame(0, BankTransaction::query()->count());

        $this->connection->update(['sync_requested_at' => Date::now()]);
        $this->artisan('bank:sync')->assertSuccessful();
        $this->assertSame(1, BankTransaction::query()->count());
    }
}
