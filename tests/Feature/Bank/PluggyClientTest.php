<?php

namespace Tests\Feature\Bank;

use App\Services\Pluggy\PluggyClient;
use App\Services\Pluggy\PluggyException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesPluggy;
use Tests\TestCase;

class PluggyClientTest extends TestCase
{
    use FakesPluggy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurePluggy();
    }

    public function test_without_credentials_nothing_is_requested(): void
    {
        config(['services.pluggy.client_id' => null]);
        Http::fake();

        $this->assertFalse(app(PluggyClient::class)->configured());

        try {
            app(PluggyClient::class)->item('item-1');
            $this->fail('Expected a PluggyException.');
        } catch (PluggyException $exception) {
            $this->assertStringContainsString('PLUGGY_CLIENT_ID', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_the_api_key_is_requested_once_and_sent_in_the_header(): void
    {
        $this->pluggyItem('item-1');
        $this->fakePluggy();

        $client = app(PluggyClient::class);
        $client->item('item-1');
        $client->accounts('item-1');

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.pluggy.test/auth'
            && $request['clientId'] === 'test-client-id'
            && $request['clientSecret'] === 'test-client-secret');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/accounts?itemId=item-1')
            && $request->header('X-API-KEY') === ['fake-api-key']);
        $this->assertSame('fake-api-key', Cache::get('pluggy.api_key'));
    }

    public function test_an_expired_api_key_is_renewed_once(): void
    {
        Cache::put('pluggy.api_key', 'expired-key', 600);
        $this->pluggyItem('item-1');
        $this->fakePluggy();

        $item = app(PluggyClient::class)->item('item-1');

        $this->assertSame('item-1', $item['id']);
        $this->assertSame('fake-api-key', Cache::get('pluggy.api_key'));
    }

    public function test_transactions_follow_the_cursor(): void
    {
        Http::fake([
            'api.pluggy.test/auth' => Http::response(['apiKey' => 'fake-api-key']),
            'api.pluggy.test/v2/transactions*' => Http::sequence()
                ->push(['results' => [['id' => 'a'], ['id' => 'b']], 'next' => '?accountId=acc-1&after=cursor-2'])
                ->push(['results' => [['id' => 'c']], 'next' => null]),
        ]);

        $transactions = app(PluggyClient::class)->transactions('acc-1', ['dateFrom' => '2026-11-01']);

        $this->assertSame(['a', 'b', 'c'], array_column($transactions, 'id'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'v2/transactions?accountId=acc-1&dateFrom=2026-11-01'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'v2/transactions?accountId=acc-1&after=cursor-2'));
    }

    public function test_errors_carry_pluggys_code_without_the_payload(): void
    {
        $this->fakePluggy();

        try {
            app(PluggyClient::class)->item('missing');
            $this->fail('Expected a PluggyException.');
        } catch (PluggyException $exception) {
            $this->assertTrue($exception->isNotFound());
            $this->assertSame('ITEM_NOT_FOUND', $exception->errorCode);
        }
    }

    public function test_connect_tokens_carry_the_client_user_id(): void
    {
        $this->fakePluggy();

        $token = app(PluggyClient::class)->connectToken('gamidas-abc', 'item-1');

        $this->assertSame('fake-connect-token', $token);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/connect_token')
            && $request['itemId'] === 'item-1'
            && $request['options']['clientUserId'] === 'gamidas-abc');
    }
}
