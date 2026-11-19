<?php

namespace Tests\Concerns;

use App\Enums\BankAccountType;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankTransaction;
use App\Models\User;
use App\Services\Bank\TransactionClassifier;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A fake Pluggy API (Http::fake) serving one or more items, and helpers to
 * build its payloads and local bank rows. Nothing here is real data.
 */
trait FakesPluggy
{
    /**
     * @var array<string, array<string, mixed>> Items by id.
     */
    protected array $pluggyItems = [];

    /**
     * @var array<string, list<array<string, mixed>>> Accounts by item id.
     */
    protected array $pluggyAccounts = [];

    /**
     * @var array<string, list<array<string, mixed>>> Transactions by account id.
     */
    protected array $pluggyTransactions = [];

    /**
     * @var array<string, list<array<string, mixed>>> Bills by account id.
     */
    protected array $pluggyBills = [];

    /**
     * @var array<string, list<array<string, mixed>>> Investments by item id.
     */
    protected array $pluggyInvestments = [];

    protected function configurePluggy(): void
    {
        config([
            'services.pluggy.client_id' => 'test-client-id',
            'services.pluggy.client_secret' => 'test-client-secret',
            'services.pluggy.base_url' => 'https://api.pluggy.test',
            'services.pluggy.webhook_token' => str_repeat('w', 40),
            'services.pluggy.webhook_signing_secret' => null,
        ]);
    }

    /**
     * Serve the registered items, accounts, bills, investments and
     * transactions, honoring the filters of GET /v2/transactions.
     */
    protected function fakePluggy(): void
    {
        Http::fake(fn (Request $request) => $this->pluggyResponse($request));
    }

    private function pluggyResponse(Request $request): PromiseInterface
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        if ($path === '/auth') {
            return Http::response(['apiKey' => 'fake-api-key']);
        }

        if ($request->header('X-API-KEY') !== ['fake-api-key']) {
            return Http::response(['message' => 'Unauthorized'], 401);
        }

        if ($path === '/connect_token') {
            return Http::response(['accessToken' => 'fake-connect-token']);
        }

        if (preg_match('~^/items/([^/]+)$~', $path, $match) === 1) {
            if (! isset($this->pluggyItems[$match[1]])) {
                return Http::response(['codeDescription' => 'ITEM_NOT_FOUND'], 404);
            }

            return $request->method() === 'DELETE'
                ? Http::response(['count' => 1])
                : Http::response($this->pluggyItems[$match[1]]);
        }

        $page = fn (array $results) => Http::response(['page' => 1, 'total' => count($results), 'totalPages' => 1, 'results' => $results]);

        return match ($path) {
            '/accounts' => $page($this->pluggyAccounts[$query['itemId'] ?? ''] ?? []),
            '/bills' => $page($this->pluggyBills[$query['accountId'] ?? ''] ?? []),
            '/investments' => $page($this->pluggyInvestments[$query['itemId'] ?? ''] ?? []),
            '/v2/transactions' => Http::response(['results' => $this->filterTransactions($query), 'next' => null]),
            default => Http::response(['codeDescription' => 'NOT_FOUND'], 404),
        };
    }

    /**
     * @param  array<array-key, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function filterTransactions(array $query): array
    {
        $ids = isset($query['ids']) ? explode(',', (string) $query['ids']) : null;

        return array_values(array_filter(
            $this->pluggyTransactions[$query['accountId'] ?? ''] ?? [],
            fn (array $transaction) => ($ids === null || in_array($transaction['id'], $ids, true))
                && (! isset($query['dateFrom']) || substr($transaction['date'], 0, 10) >= $query['dateFrom'])
                && (! isset($query['createdAtFrom']) || $transaction['createdAt'] >= $query['createdAtFrom']),
        ));
    }

    /**
     * Register an item with a checking account and a credit card.
     *
     * @param  array<string, mixed>  $item
     */
    protected function pluggyItem(string $id = 'item-nubank', array $item = []): void
    {
        $this->pluggyItems[$id] = [
            'id' => $id,
            'status' => 'UPDATED',
            'executionStatus' => 'SUCCESS',
            'error' => null,
            'connector' => [
                'id' => 612,
                'name' => 'Nubank',
                'imageUrl' => 'https://cdn.pluggy.test/nubank.svg',
                'primaryColor' => '820AD1',
            ],
            'consentExpiresAt' => '2027-11-01T00:00:00.000Z',
            'lastUpdatedAt' => '2026-11-19T09:00:00.000Z',
            ...$item,
        ];

        $this->pluggyAccounts[$id] ??= [
            $this->pluggyAccount('acc-checking', 'BANK', ['name' => 'Conta Corrente', 'number' => '1234567-8', 'balance' => 1520.35]),
            $this->pluggyAccount('acc-card', 'CREDIT', [
                'name' => 'Nubank Ultravioleta',
                'number' => '4321',
                'balance' => 830.1,
                'creditData' => ['creditLimit' => 5000, 'availableCreditLimit' => 4169.9, 'balanceDueDate' => '2026-12-10T00:00:00.000Z'],
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function pluggyAccount(string $id, string $type, array $overrides = []): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'subtype' => $type === 'CREDIT' ? 'CREDIT_CARD' : 'CHECKING_ACCOUNT',
            'name' => 'Conta',
            'number' => null,
            'balance' => 0,
            'currencyCode' => 'BRL',
            ...$overrides,
        ];
    }

    /**
     * Add a transaction to an account of the fake API.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function pluggyTransaction(string $accountId, string $id, array $overrides = []): array
    {
        $transaction = [
            'id' => $id,
            'accountId' => $accountId,
            'date' => '2026-11-18T00:00:00.000Z',
            'description' => 'Mercado Bom Preço',
            'amount' => 120.5,
            'type' => 'DEBIT',
            'status' => 'POSTED',
            'createdAt' => '2026-11-18T10:00:00.000Z',
            ...$overrides,
        ];

        $this->pluggyTransactions[$accountId] = array_values(array_filter(
            $this->pluggyTransactions[$accountId] ?? [],
            fn (array $existing) => $existing['id'] !== $id,
        ));
        $this->pluggyTransactions[$accountId][] = $transaction;

        return $transaction;
    }

    protected function removePluggyTransaction(string $accountId, string $id): void
    {
        $this->pluggyTransactions[$accountId] = array_values(array_filter(
            $this->pluggyTransactions[$accountId] ?? [],
            fn (array $existing) => $existing['id'] !== $id,
        ));
    }

    /**
     * A connection stored locally, as after connecting.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function createBankConnection(User $user, string $itemId = 'item-nubank', array $attributes = []): BankConnection
    {
        return BankConnection::create([
            'user_id' => $user->id,
            'item_id' => $itemId,
            'item_hash' => BankConnection::hashItem($itemId),
            'connector_id' => 612,
            'connector_name' => 'Nubank',
            'status' => 'UPDATED',
            'import_from' => Date::today()->subDays(30),
            ...$attributes,
        ]);
    }

    protected function createBankAccount(BankConnection $connection, BankAccountType $type = BankAccountType::Bank, ?string $pluggyId = null): BankAccount
    {
        return BankAccount::create([
            'bank_connection_id' => $connection->id,
            'user_id' => $connection->user_id,
            'pluggy_id' => $pluggyId ?? Str::uuid()->toString(),
            'type' => $type,
            'name' => $type === BankAccountType::Credit ? 'Cartão' : 'Conta',
        ]);
    }

    /**
     * A transaction already imported, built by the classifier from a Pluggy
     * payload.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function createBankTransaction(BankAccount $account, array $payload = []): BankTransaction
    {
        $payload = [
            'id' => Str::uuid()->toString(),
            'date' => Date::today()->toDateString().'T00:00:00.000Z',
            'description' => 'Mercado Bom Preço',
            'amount' => 120.5,
            'type' => 'DEBIT',
            'status' => 'POSTED',
            ...$payload,
        ];

        return BankTransaction::create([
            ...app(TransactionClassifier::class)->classify($payload, $account->type),
            'bank_account_id' => $account->id,
            'user_id' => $account->user_id,
            'pluggy_id' => $payload['id'],
        ]);
    }

    protected function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, config('app.timezone'));
    }
}
