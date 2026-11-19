<?php

namespace App\Services\Pluggy;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A thin client for the Pluggy REST API (there is no official PHP SDK).
 *
 * The server authenticates with the application's client id and secret
 * (POST /auth); the API key it returns lives 2 hours and is cached. The
 * browser never sees it: the Connect widget gets a short-lived connect token.
 * Payloads are never logged, since they carry financial data.
 */
class PluggyClient
{
    private const API_KEY_CACHE = 'pluggy.api_key';

    /**
     * API keys live 2 hours; renew a bit before that.
     */
    private const API_KEY_TTL_SECONDS = 100 * 60;

    /**
     * Safety cap on pages read from a single listing.
     */
    private const MAX_PAGES = 40;

    public function configured(): bool
    {
        return filled(config('services.pluggy.client_id')) && filled(config('services.pluggy.client_secret'));
    }

    /**
     * A token for the Connect widget (valid 30 minutes). With an item id the
     * widget opens that connection to renew it.
     */
    public function connectToken(string $clientUserId, ?string $itemId = null): string
    {
        $body = ['options' => ['clientUserId' => $clientUserId, 'avoidDuplicates' => true]];

        if ($itemId !== null) {
            $body['itemId'] = $itemId;
        }

        $token = $this->request('post', 'connect_token', [], $body)['accessToken'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new PluggyException('Pluggy não devolveu o token de conexão.');
        }

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    public function item(string $itemId): array
    {
        return $this->request('get', 'items/'.rawurlencode($itemId));
    }

    /**
     * Revoke the connection at Pluggy. An item that no longer exists there
     * counts as deleted.
     */
    public function deleteItem(string $itemId): void
    {
        try {
            $this->request('delete', 'items/'.rawurlencode($itemId));
        } catch (PluggyException $exception) {
            if (! $exception->isNotFound()) {
                throw $exception;
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function accounts(string $itemId): array
    {
        return $this->paged('accounts', ['itemId' => $itemId]);
    }

    /**
     * Closed bills of a credit card account.
     *
     * @return list<array<string, mixed>>
     */
    public function bills(string $accountId): array
    {
        return $this->paged('bills', ['accountId' => $accountId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function investments(string $itemId): array
    {
        return $this->paged('investments', ['itemId' => $itemId]);
    }

    /**
     * Transactions of an account (GET /v2/transactions), following the
     * cursor. Filters: dateFrom/dateTo (Y-m-d), createdAtFrom (ISO 8601, not
     * together with dateFrom) or ids (comma-separated, up to 500).
     *
     * @param  array<string, string>  $filters
     * @return list<array<string, mixed>>
     */
    public function transactions(string $accountId, array $filters = []): array
    {
        $query = ['accountId' => $accountId, ...$filters];
        $results = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $data = $this->request('get', 'v2/transactions', $query);

            foreach ($this->results($data) as $transaction) {
                $results[] = $transaction;
            }

            $next = $data['next'] ?? null;

            if (! is_string($next) || $next === '') {
                break;
            }

            // "next" is a ready query string ("?accountId=...&after=..."):
            // read it as parameters so requests always go to the API host.
            $nextQueryString = parse_url($next, PHP_URL_QUERY);
            parse_str(ltrim(is_string($nextQueryString) ? $nextQueryString : $next, '?'), $nextQuery);
            $query = array_filter($nextQuery, fn ($value, $key) => is_string($key) && is_string($value), ARRAY_FILTER_USE_BOTH);
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function webhooks(): array
    {
        return $this->results($this->request('get', 'webhooks'));
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function createWebhook(string $url, string $event, array $headers): array
    {
        return $this->request('post', 'webhooks', [], ['url' => $url, 'event' => $event, 'headers' => $headers]);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function updateWebhook(string $id, array $changes): array
    {
        return $this->request('patch', 'webhooks/'.rawurlencode($id), [], $changes);
    }

    /**
     * Read every page of a page/pageSize listing.
     *
     * @param  array<string, string>  $query
     * @return list<array<string, mixed>>
     */
    private function paged(string $path, array $query): array
    {
        $results = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $data = $this->request('get', $path, [...$query, 'page' => (string) $page, 'pageSize' => '500']);

            foreach ($this->results($data) as $result) {
                $results[] = $result;
            }

            $totalPages = (int) ($data['totalPages'] ?? 1);

            if ($page >= $totalPages) {
                break;
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function results(array $data): array
    {
        $results = $data['results'] ?? [];

        return is_array($results) ? array_values(array_filter($results, 'is_array')) : [];
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $query = [], ?array $body = null, bool $retry = true): array
    {
        $response = $this->send($this->http()->withHeaders(['X-API-KEY' => $this->apiKey()]), $method, $path, $query, $body);

        if ($response->status() === 401 && $retry) {
            // The cached key expired early or was revoked: authenticate again.
            Cache::forget(self::API_KEY_CACHE);

            return $this->request($method, $path, $query, $body, false);
        }

        return $this->decode($response, $path);
    }

    private function apiKey(): string
    {
        if (! $this->configured()) {
            throw PluggyException::notConfigured();
        }

        $key = Cache::get(self::API_KEY_CACHE);

        if (is_string($key) && $key !== '') {
            return $key;
        }

        $response = $this->send($this->http(), 'post', 'auth', [], [
            'clientId' => (string) config('services.pluggy.client_id'),
            'clientSecret' => (string) config('services.pluggy.client_secret'),
        ]);

        $key = $this->decode($response, 'auth')['apiKey'] ?? null;

        if (! is_string($key) || $key === '') {
            throw new PluggyException('Pluggy não devolveu a chave de API.');
        }

        Cache::put(self::API_KEY_CACHE, $key, self::API_KEY_TTL_SECONDS);

        return $key;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.pluggy.base_url'), '/').'/')
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(20);
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, mixed>|null  $body
     */
    private function send(PendingRequest $http, string $method, string $path, array $query, ?array $body): Response
    {
        try {
            return match ($method) {
                'get' => $http->get($path, $query),
                'delete' => $http->delete($path),
                'patch' => $http->patch($path, $body ?? []),
                default => $http->post($path, $body ?? []),
            };
        } catch (ConnectionException) {
            Log::warning('Pluggy unreachable', ['path' => $this->pathForLog($path)]);

            throw new PluggyException('Não foi possível falar com a Pluggy. Tente de novo em instantes.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response, string $path): array
    {
        if ($response->successful()) {
            $json = $response->json();

            return is_array($json) ? $json : [];
        }

        $code = $response->json('codeDescription');
        $code = is_string($code) ? $code : null;

        Log::warning('Pluggy request failed', ['path' => $this->pathForLog($path), 'status' => $response->status(), 'code' => $code]);

        throw new PluggyException(match (true) {
            $response->status() === 401 => 'A Pluggy recusou as credenciais (PLUGGY_CLIENT_ID / PLUGGY_CLIENT_SECRET).',
            $response->status() === 404 => 'A Pluggy não encontrou esta conexão.',
            $response->status() === 429 => 'Limite de requisições da Pluggy atingido. Tente de novo em um minuto.',
            default => 'A Pluggy respondeu com erro ('.$response->status().($code !== null ? ", {$code}" : '').').',
        }, $response->status(), $code);
    }

    /**
     * The path without ids, so logs never carry item or account ids.
     */
    private function pathForLog(string $path): string
    {
        return (string) preg_replace('~^(items|webhooks)/.+$~', '$1/{id}', $path);
    }
}
