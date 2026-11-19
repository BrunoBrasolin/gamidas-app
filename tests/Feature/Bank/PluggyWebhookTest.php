<?php

namespace Tests\Feature\Bank;

use App\Jobs\SyncBankConnection;
use App\Models\BankConnection;
use App\Models\BankTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesHousehold;
use Tests\Concerns\FakesPluggy;
use Tests\TestCase;

class PluggyWebhookTest extends TestCase
{
    use CreatesHousehold, FakesPluggy, RefreshDatabase;

    private BankConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-11-19 12:00:00');
        $this->configurePluggy();
        $this->createHousehold();
        $this->connection = $this->createBankConnection($this->bruno);
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function send(array $payload, ?array $headers = null): TestResponse
    {
        return $this->postJson(route('webhooks.pluggy'), $payload, $headers ?? ['X-Gamidas-Token' => str_repeat('w', 40)]);
    }

    public function test_deliveries_without_a_valid_token_are_refused(): void
    {
        Bus::fake();

        $this->send(['event' => 'item/updated', 'itemId' => 'item-nubank'], [])->assertUnauthorized();
        $this->send(['event' => 'item/updated', 'itemId' => 'item-nubank'], ['X-Gamidas-Token' => 'wrong'])->assertUnauthorized();

        config(['services.pluggy.webhook_token' => null]);
        $this->send(['event' => 'item/updated', 'itemId' => 'item-nubank'])->assertUnauthorized();

        Bus::assertNothingDispatched();
    }

    public function test_a_valid_pluggy_signature_is_accepted(): void
    {
        Bus::fake();
        config(['services.pluggy.webhook_token' => null, 'services.pluggy.webhook_signing_secret' => 'whsec_test']);

        $body = json_encode(['event' => 'item/updated', 'itemId' => 'item-nubank', 'eventId' => 'evt-1']);
        $timestamp = (string) Date::now()->getTimestamp();
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_test');

        $this->call('POST', route('webhooks.pluggy'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PLUGGY_SIGNATURE' => $signature,
        ], $body)->assertOk()->assertExactJson(['status' => 'queued']);

        $old = 't='.($timestamp - 600).',v1='.hash_hmac('sha256', ($timestamp - 600).'.'.$body, 'whsec_test');

        $this->call('POST', route('webhooks.pluggy'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PLUGGY_SIGNATURE' => $old,
        ], $body)->assertUnauthorized();
    }

    public function test_events_of_unknown_items_are_ignored(): void
    {
        Bus::fake();

        $this->send(['event' => 'transactions/created', 'itemId' => 'someone-else'])->assertOk()->assertExactJson(['status' => 'ignored']);
        $this->send(['itemId' => 'item-nubank'])->assertUnprocessable();

        Bus::assertNothingDispatched();
    }

    public function test_new_transactions_request_a_sync_after_the_response(): void
    {
        Bus::fake();

        $this->send([
            'event' => 'transactions/created',
            'eventId' => 'evt-1',
            'itemId' => 'item-nubank',
            'accountId' => 'acc-card',
            'transactionsCreatedAtFrom' => '2026-11-19T10:00:00.000Z',
        ])->assertOk()->assertExactJson(['status' => 'queued']);

        $this->assertNotNull($this->connection->fresh()->sync_requested_at);
        Bus::assertDispatchedAfterResponse(SyncBankConnection::class, fn (SyncBankConnection $job) => $job->connectionId === $this->connection->id);
    }

    public function test_updated_transactions_are_fetched_by_id(): void
    {
        Bus::fake();

        $this->send(['event' => 'transactions/updated', 'itemId' => 'item-nubank', 'accountId' => 'acc-card', 'transactionIds' => ['tx-1', 'tx-2']])->assertOk();

        Bus::assertDispatchedAfterResponse(SyncBankConnection::class, fn (SyncBankConnection $job) => $job->updatedIds === ['acc-card' => ['tx-1', 'tx-2']]);
    }

    public function test_deleted_transactions_are_flagged_right_away(): void
    {
        $account = $this->createBankAccount($this->connection);
        $deleted = $this->createBankTransaction($account, ['id' => 'tx-gone']);
        $kept = $this->createBankTransaction($account, ['id' => 'tx-kept']);

        $this->send(['event' => 'transactions/deleted', 'itemId' => 'item-nubank', 'accountId' => $account->pluggy_id, 'transactionIds' => ['tx-gone']])
            ->assertOk()
            ->assertExactJson(['status' => 'recorded']);

        $this->assertNotNull($deleted->fresh()->removed_at);
        $this->assertNull($kept->fresh()->removed_at);
    }

    public function test_a_deleted_item_asks_to_reconnect(): void
    {
        $this->send(['event' => 'item/deleted', 'itemId' => 'item-nubank'])->assertOk();

        $this->assertSame('DELETED', $this->connection->fresh()->status);
    }

    public function test_the_sync_runs_after_the_response(): void
    {
        $this->pluggyItem();
        $this->pluggyTransaction('acc-checking', 'tx-1');
        $this->fakePluggy();

        $this->send(['event' => 'item/updated', 'itemId' => 'item-nubank', 'triggeredBy' => 'SYNC'])->assertOk();

        $this->assertSame(1, BankTransaction::query()->count());
        $this->assertNull($this->connection->fresh()->sync_requested_at);
    }
}
