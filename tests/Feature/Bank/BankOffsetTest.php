<?php

namespace Tests\Feature\Bank;

use App\Enums\BankAccountType;
use App\Enums\BankReviewStatus;
use App\Enums\BankRuleAction;
use App\Models\AuditLog;
use App\Models\BankConnection;
use App\Models\BankRule;
use App\Models\BankTransaction;
use App\Models\PersonalTransaction;
use App\Services\Bank\BankCatalogService;
use App\Services\Bank\BankOffsetService;
use App\Services\Bank\BankSyncService;
use App\Services\Bank\CatalogChoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\Concerns\FakesPluggy;
use Tests\TestCase;

class BankOffsetTest extends TestCase
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
        $this->connection = $this->createBankConnection($this->bruno, 'item-nubank', ['import_from' => '2026-10-01']);
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    private function sync(): void
    {
        app(BankSyncService::class)->sync($this->connection->fresh());
    }

    private function iofCharge(string $id = 'tx-iof', string $date = '2026-10-23'): void
    {
        $this->pluggyTransaction('acc-card', $id, ['date' => $date.'T00:00:00.000Z', 'description' => 'IOF de "Zenrows.Com"', 'amount' => 12.77, 'type' => 'DEBIT']);
    }

    private function iofRefund(string $id = 'tx-iof-back', string $date = '2026-10-22'): void
    {
        $this->pluggyTransaction('acc-card', $id, ['date' => $date.'T00:00:00.000Z', 'description' => 'IOF de volta de Zenrows.Com', 'amount' => -12.77, 'type' => 'CREDIT']);
    }

    private function tx(string $pluggyId): BankTransaction
    {
        return BankTransaction::query()->where('pluggy_id', $pluggyId)->sole();
    }

    private function assertPaired(BankTransaction $first, BankTransaction $second): void
    {
        $first->refresh();
        $second->refresh();

        foreach ([[$first, $second], [$second, $first]] as [$side, $other]) {
            $this->assertSame(BankReviewStatus::Ignored, $side->review_status);
            $this->assertSame(BankTransaction::IGNORED_OFFSET, $side->ignore_reason);
            $this->assertSame($other->id, $side->offset_transaction_id);
        }
    }

    public function test_a_refund_dated_before_its_charge_cancels_it_out_automatically(): void
    {
        $this->iofCharge();
        $this->iofRefund();

        $this->sync();

        $this->assertPaired($this->tx('tx-iof'), $this->tx('tx-iof-back'));
        $this->assertSame(0, app(BankCatalogService::class)->pendingOf($this->bruno)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'bank.offset', 'user_id' => null]);
    }

    public function test_the_refund_arriving_in_a_later_sync_pairs_too(): void
    {
        $this->iofCharge();
        $this->sync();
        $this->assertTrue($this->tx('tx-iof')->isPending());

        Date::setTestNow('2026-11-20 12:00:00');
        $this->iofRefund();
        $this->pluggyTransactions['acc-card'][1]['createdAt'] = '2026-11-20T08:00:00.000Z';
        $this->sync();

        $this->assertPaired($this->tx('tx-iof'), $this->tx('tx-iof-back'));
    }

    public function test_pairs_imported_before_matching_are_caught_when_read_again(): void
    {
        $card = $this->createBankAccount($this->connection, BankAccountType::Credit, 'acc-card');
        $charge = $this->createBankTransaction($card, ['id' => 'tx-iof', 'date' => '2026-10-23T00:00:00.000Z', 'description' => 'IOF de "Zenrows.Com"', 'amount' => 12.77]);
        $refund = $this->createBankTransaction($card, ['id' => 'tx-iof-back', 'date' => '2026-10-22T00:00:00.000Z', 'description' => 'IOF de volta de Zenrows.Com', 'amount' => -12.77, 'type' => 'CREDIT']);
        $this->connection->update(['synced_at' => Date::now()->subHour()]);
        $this->iofCharge();
        $this->iofRefund();

        $this->sync();

        $this->assertPaired($charge, $refund);
    }

    public function test_an_automatic_rule_does_not_catalogue_a_charge_its_refund_cancels(): void
    {
        BankRule::create([
            'user_id' => $this->bruno->id,
            'pattern' => 'iof de zenrows com',
            'label' => 'IOF de Zenrows',
            'action' => BankRuleAction::Personal,
            'auto_apply' => true,
        ]);
        $this->iofCharge();
        $this->iofRefund();

        $this->sync();

        $this->assertPaired($this->tx('tx-iof'), $this->tx('tx-iof-back'));
        $this->assertSame(0, PersonalTransaction::query()->count());
    }

    public function test_a_weak_pair_is_only_suggested(): void
    {
        $this->pluggyTransaction('acc-card', 'tx-buy', ['date' => '2026-11-10T00:00:00.000Z', 'description' => 'Loja Exemplo', 'amount' => 50]);
        $this->pluggyTransaction('acc-card', 'tx-credit', ['date' => '2026-11-12T00:00:00.000Z', 'description' => 'Crédito na fatura', 'amount' => -50, 'type' => 'CREDIT']);

        $this->sync();

        $buy = $this->tx('tx-buy');
        $credit = $this->tx('tx-credit');
        $this->assertTrue($buy->isPending());
        $this->assertTrue($credit->isPending());

        $suggestion = app(BankCatalogService::class)->suggest($credit);
        $this->assertSame('offset', $suggestion?->choice->action);
        $this->assertSame($buy->id, $suggestion->choice->offsetTransactionId);

        $this->actingAs($this->bruno)
            ->get(route('bank.inbox'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('transactions.data.0.id', $credit->id)
                ->where('transactions.data.0.suggestion.action', 'offset')
                ->where('transactions.data.0.offset_candidates.0.id', $buy->id));

        $this->actingAs($this->bruno)->post(route('bank.transactions.confirm', $credit))->assertSessionHasNoErrors();

        $this->assertPaired($buy, $credit);
    }

    public function test_weak_pairs_need_a_card_or_the_same_counterparty(): void
    {
        $checking = $this->createBankAccount($this->connection, BankAccountType::Bank, 'acc-checking');
        $sent = $this->createBankTransaction($checking, ['description' => 'Pix', 'amount' => -100, 'paymentData' => ['receiver' => ['name' => 'Ana Exemplo']]]);
        $other = $this->createBankTransaction($checking, ['description' => 'Pix', 'amount' => 100, 'type' => 'CREDIT', 'paymentData' => ['payer' => ['name' => 'Beto Exemplo']]]);
        $back = $this->createBankTransaction($checking, ['description' => 'Pix', 'amount' => 100, 'type' => 'CREDIT', 'paymentData' => ['payer' => ['name' => 'Ana Exemplo']]]);

        $catalog = app(BankCatalogService::class);

        $this->assertNull($catalog->suggest($other)?->choice->offsetTransactionId);
        $this->assertSame($sent->id, $catalog->suggest($back)?->choice->offsetTransactionId);
    }

    public function test_a_catalogued_charge_is_offered_to_undo_and_offset(): void
    {
        $this->iofCharge();
        $this->sync();
        $charge = $this->tx('tx-iof');
        app(BankCatalogService::class)->catalog($charge, $this->bruno, new CatalogChoice('personal'));
        $this->assertSame(1, PersonalTransaction::query()->count());

        Date::setTestNow('2026-11-20 12:00:00');
        $this->iofRefund();
        $this->pluggyTransactions['acc-card'][1]['createdAt'] = '2026-11-20T08:00:00.000Z';
        $this->sync();

        $refund = $this->tx('tx-iof-back');
        $this->assertTrue($refund->isPending());

        $suggestion = app(BankCatalogService::class)->suggest($refund);
        $this->assertSame('offset', $suggestion?->choice->action);
        $this->assertStringContainsString('já catalogada', $suggestion->label);

        $this->actingAs($this->bruno)->post(route('bank.transactions.confirm', $refund))->assertSessionHasNoErrors();

        $this->assertPaired($charge, $refund);
        $this->assertSame(0, PersonalTransaction::query()->count());
    }

    public function test_undoing_and_offsetting_fails_while_the_created_expense_is_paid(): void
    {
        $card = $this->createBankAccount($this->connection, BankAccountType::Credit, 'acc-card');
        $charge = $this->createBankTransaction($card, ['description' => 'Loja Exemplo', 'amount' => 80]);
        app(BankCatalogService::class)->catalog($charge, $this->bruno, new CatalogChoice('household'));
        $this->actingAs($this->leticia)->post(route('expenses.payment.store', $charge->fresh()->expense_occurrence_id))->assertSessionHasNoErrors();

        $refund = $this->createBankTransaction($card, ['description' => 'Estorno Loja Exemplo', 'amount' => -80, 'type' => 'CREDIT']);

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.confirm', $refund))
            ->assertSessionHasErrors('transaction');

        $this->assertTrue($charge->fresh()->isCatalogued());
        $this->assertTrue($refund->fresh()->isPending());
    }

    public function test_undo_on_either_side_brings_both_back(): void
    {
        $this->iofCharge();
        $this->iofRefund();
        $this->sync();
        $charge = $this->tx('tx-iof');
        $refund = $this->tx('tx-iof-back');

        $this->actingAs($this->bruno)->delete(route('bank.transactions.reopen', $refund))->assertSessionHasNoErrors();

        foreach ([$charge->fresh(), $refund->fresh()] as $side) {
            $this->assertTrue($side->isPending());
            $this->assertNull($side->offset_transaction_id);
            $this->assertNull($side->ignore_reason);
        }

        $this->assertSame(2, AuditLog::query()->where('action', 'bank.reopened')->count());
    }

    public function test_pairs_are_made_and_shown_by_hand(): void
    {
        $card = $this->createBankAccount($this->connection, BankAccountType::Credit, 'acc-card');
        $buy = $this->createBankTransaction($card, ['description' => 'Loja Exemplo', 'amount' => 30, 'date' => '2026-10-01T00:00:00.000Z']);
        $credit = $this->createBankTransaction($card, ['description' => 'Ajuste', 'amount' => -30, 'type' => 'CREDIT']);

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $credit), ['action' => 'offset', 'offset_transaction_id' => $buy->id])
            ->assertSessionHasNoErrors();

        $this->assertPaired($buy, $credit);
        $this->assertDatabaseHas('audit_logs', ['action' => 'bank.offset', 'user_id' => $this->bruno->id]);

        $this->actingAs($this->bruno)
            ->get(route('bank.inbox', ['status' => 'ignored']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('transactions.data.0.offset.display_name', 'Loja Exemplo')
                ->where('transactions.data.1.offset.display_name', 'Ajuste'));
    }

    public function test_pairs_never_cross_accounts_values_or_members(): void
    {
        $card = $this->createBankAccount($this->connection, BankAccountType::Credit, 'acc-card');
        $checking = $this->createBankAccount($this->connection, BankAccountType::Bank, 'acc-checking');
        $charge = $this->createBankTransaction($card, ['description' => 'IOF de Exemplo', 'amount' => 10]);
        $otherAccount = $this->createBankTransaction($checking, ['description' => 'IOF de volta de Exemplo', 'amount' => 10, 'type' => 'CREDIT']);
        $otherValue = $this->createBankTransaction($card, ['description' => 'IOF de volta de Exemplo', 'amount' => -9.99, 'type' => 'CREDIT']);

        $leticiaCard = $this->createBankAccount($this->createBankConnection($this->leticia, 'item-leticia'), BankAccountType::Credit);
        $leticiaRefund = $this->createBankTransaction($leticiaCard, ['description' => 'IOF de volta de Exemplo', 'amount' => -10, 'type' => 'CREDIT']);

        $offsets = app(BankOffsetService::class);
        $this->assertNull($offsets->find($charge));
        $this->assertNull($offsets->find($otherAccount));
        $this->assertNull($offsets->find($otherValue));
        $this->assertNull($offsets->find($leticiaRefund));

        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $charge), ['action' => 'offset', 'offset_transaction_id' => $otherAccount->id])
            ->assertSessionHasErrors('offset_transaction_id');
        $this->actingAs($this->bruno)
            ->post(route('bank.transactions.catalog', $charge), ['action' => 'offset', 'offset_transaction_id' => $leticiaRefund->id])
            ->assertSessionHasErrors('offset_transaction_id');
    }

    public function test_the_closest_of_two_equal_charges_is_chosen(): void
    {
        $this->iofCharge('tx-iof-1', '2026-11-01');
        $this->iofCharge('tx-iof-2', '2026-11-15');
        $this->iofRefund('tx-iof-back', '2026-11-14');

        $this->sync();

        $this->assertPaired($this->tx('tx-iof-2'), $this->tx('tx-iof-back'));
        $this->assertTrue($this->tx('tx-iof-1')->isPending());
    }

    public function test_a_charge_the_bank_recreates_keeps_its_pair(): void
    {
        $this->pluggyTransaction('acc-card', 'tx-iof', ['date' => '2026-11-10T00:00:00.000Z', 'description' => 'IOF de "Zenrows.Com"', 'amount' => 12.77, 'status' => 'PENDING']);
        $this->iofRefund('tx-iof-back', '2026-11-09');
        $this->sync();
        $refund = $this->tx('tx-iof-back');
        $this->assertSame(BankTransaction::IGNORED_OFFSET, $refund->ignore_reason);

        Date::setTestNow('2026-11-20 12:00:00');
        $this->removePluggyTransaction('acc-card', 'tx-iof');
        $this->pluggyTransaction('acc-card', 'tx-iof-new', [
            'date' => '2026-11-10T00:00:00.000Z', 'description' => 'IOF de "Zenrows.Com"', 'amount' => 12.77, 'createdAt' => '2026-11-20T08:00:00.000Z',
        ]);
        $this->sync();

        $this->assertDatabaseMissing('bank_transactions', ['pluggy_id' => 'tx-iof']);
        $this->assertPaired($this->tx('tx-iof-new'), $refund);
    }

    public function test_a_side_the_bank_removes_releases_the_other(): void
    {
        $this->pluggyTransaction('acc-card', 'tx-iof', ['date' => '2026-11-10T00:00:00.000Z', 'description' => 'IOF de "Zenrows.Com"', 'amount' => 12.77, 'status' => 'PENDING']);
        $this->iofRefund('tx-iof-back', '2026-11-09');
        $this->sync();

        $this->removePluggyTransaction('acc-card', 'tx-iof');
        $this->sync();

        $refund = $this->tx('tx-iof-back');
        $this->assertTrue($refund->isPending());
        $this->assertNull($refund->offset_transaction_id);
        $this->assertNotNull($this->tx('tx-iof')->removed_at);
        $this->assertNull($this->tx('tx-iof')->offset_transaction_id);
    }
}
