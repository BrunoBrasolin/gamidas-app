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
