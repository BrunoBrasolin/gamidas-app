<?php

namespace Tests\Feature\Bank;

use App\Enums\BankAccountType;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankTransaction;
use App\Models\ExpenseOccurrence;
use App\Services\Bank\BankCatalogService;
use App\Services\Bank\BankConnectionService;
use App\Services\Bank\CatalogChoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\Concerns\FakesPluggy;
use Tests\TestCase;

class BankConnectionTest extends TestCase
{
    use CreatesHousehold, FakesPluggy, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-11-19 12:00:00');
        $this->configurePluggy();
        $this->createHousehold();
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_connecting_by_item_id_stores_the_item_encrypted_and_imports_it(): void
    {
        $this->pluggyItem('item-nubank');
        $this->pluggyTransaction('acc-checking', 'tx-1');
        $this->fakePluggy();

        $this->actingAs($this->bruno)
            ->post(route('bank.connections.store'), ['item_id' => 'item-nubank'])
            ->assertRedirect(route('bank.index'))
            ->assertSessionHasNoErrors();

        $connection = BankConnection::query()->sole();
        $this->assertSame($this->bruno->id, $connection->user_id);
        $this->assertSame('item-nubank', $connection->item_id);
        $this->assertSame('2026-10-20', $connection->import_from->toDateString());
        $this->assertDatabaseMissing('bank_connections', ['item_id' => 'item-nubank']);
        $this->assertSame(BankConnection::hashItem('item-nubank'), $connection->item_hash);
        $this->assertSame(2, BankAccount::query()->count());
        $this->assertSame(1, BankTransaction::query()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'bank.connected', 'user_id' => $this->bruno->id]);
    }

    public function test_items_created_for_someone_else_or_unknown_are_refused(): void
    {
        $this->pluggyItem('item-other', ['clientUserId' => 'gamidas-someone-else']);
        $this->fakePluggy();

        $this->actingAs($this->bruno)->post(route('bank.connections.store'), ['item_id' => 'item-other'])->assertSessionHasErrors('item_id');
        $this->actingAs($this->bruno)->post(route('bank.connections.store'), ['item_id' => 'item-missing'])->assertSessionHasErrors('item_id');
        $this->actingAs($this->bruno)->post(route('bank.connections.store'), ['item_id' => '../items'])->assertSessionHasErrors('item_id');

        $this->assertSame(0, BankConnection::query()->count());
    }

    public function test_items_created_by_the_widget_for_the_member_are_accepted(): void
    {
        $this->pluggyItem('item-widget', ['clientUserId' => app(BankConnectionService::class)->clientUserId($this->bruno)]);
        $this->fakePluggy();

        $this->actingAs($this->bruno)->post(route('bank.connections.store'), ['item_id' => 'item-widget'])->assertSessionHasNoErrors();

        $this->assertSame(1, BankConnection::query()->count());
    }

    public function test_a_bank_connected_by_one_member_cannot_be_taken_by_the_other(): void
    {
        $this->pluggyItem('item-nubank');
        $this->fakePluggy();
        $this->createBankConnection($this->bruno, 'item-nubank');

        $this->actingAs($this->leticia)
            ->post(route('bank.connections.store'), ['item_id' => 'item-nubank'])
            ->assertSessionHasErrors('item_id');

        $this->assertSame($this->bruno->id, BankConnection::query()->sole()->user_id);
    }

    public function test_the_connect_token_comes_from_the_server(): void
    {
        $this->fakePluggy();
        $connection = $this->createBankConnection($this->bruno, 'item-nubank');

        $this->actingAs($this->bruno)
            ->postJson(route('bank.connect-token'))
            ->assertOk()
            ->assertExactJson(['token' => 'fake-connect-token', 'item_id' => null]);

        $this->actingAs($this->bruno)
            ->postJson(route('bank.connect-token'), ['connection' => $connection->id])
            ->assertOk()
            ->assertJson(['item_id' => 'item-nubank']);

        $this->actingAs($this->leticia)
            ->postJson(route('bank.connect-token'), ['connection' => $connection->id])
            ->assertNotFound();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/connect_token')
            && $request['options']['clientUserId'] === app(BankConnectionService::class)->clientUserId($this->bruno));
    }

    public function test_the_accounts_page_is_private(): void
    {
        $connection = $this->createBankConnection($this->bruno);
        $this->createBankAccount($connection, BankAccountType::Credit);

        $this->actingAs($this->bruno)
            ->get(route('bank.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('bank/accounts')
                ->where('configured', true)
                ->where('webhook.configured', true)
                ->has('connections', 1)
                ->where('connections.0.connector_name', 'Nubank')
                ->missing('connections.0.item_id')
                ->has('connections.0.accounts', 1));

        $this->actingAs($this->leticia)
            ->get(route('bank.index'))
            ->assertInertia(fn (Assert $page) => $page->has('connections', 0));

        $this->actingAs($this->leticia)->post(route('bank.connections.sync', $connection))->assertNotFound();
        $this->actingAs($this->leticia)->delete(route('bank.connections.destroy', $connection))->assertNotFound();
    }

    public function test_disconnecting_revokes_and_deletes_imported_data_but_keeps_catalogued_expenses(): void
    {
        $this->pluggyItem('item-nubank');
        $this->fakePluggy();
        $connection = $this->createBankConnection($this->bruno);
        $account = $this->createBankAccount($connection);
        $transaction = $this->createBankTransaction($account);
        app(BankCatalogService::class)->catalog($transaction, $this->bruno, new CatalogChoice('household'));

        $this->actingAs($this->bruno)
            ->delete(route('bank.connections.destroy', $connection))
            ->assertRedirect(route('bank.index'));

        $this->assertSame(0, BankConnection::query()->count());
        $this->assertSame(0, BankAccount::query()->count());
        $this->assertSame(0, BankTransaction::query()->count());
        $this->assertSame(1, ExpenseOccurrence::query()->count());
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/items/item-nubank'));
    }

    public function test_sync_now_reports_pluggy_errors_without_failing(): void
    {
        $this->fakePluggy();
        $connection = $this->createBankConnection($this->bruno, 'item-gone');

        $this->actingAs($this->bruno)
            ->post(route('bank.connections.sync', $connection))
            ->assertRedirect();

        $this->assertSame('A Pluggy não encontrou esta conexão.', $connection->fresh()->sync_error);
    }

    public function test_the_webhook_registration_command_sends_the_token_header(): void
    {
        URL::forceRootUrl('https://gamidas.example.test');
        URL::forceScheme('https');

        Http::fake([
            'api.pluggy.test/auth' => Http::response(['apiKey' => 'fake-api-key']),
            'api.pluggy.test/webhooks' => Http::sequence()
                ->push(['results' => []])
                ->push(['id' => 'wh-1']),
        ]);

        $this->artisan('bank:webhook')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/webhooks')
            && $request['url'] === 'https://gamidas.example.test/api/webhooks/pluggy'
            && $request['event'] === 'all'
            && $request['headers'] === ['X-Gamidas-Token' => str_repeat('w', 40)]);
    }
}
