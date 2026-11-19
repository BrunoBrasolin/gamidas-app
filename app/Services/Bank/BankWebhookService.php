<?php

namespace App\Services\Bank;

use App\Jobs\SyncBankConnection;
use App\Models\BankConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * Handles Pluggy webhooks. The payload is only a notice ("item X has new
 * transactions"): nothing in it is trusted as data. The connection is found
 * by the item id and its data is fetched from the API with the server's own
 * credentials, after the response is sent. Unknown items are ignored.
 */
final class BankWebhookService
{
    /**
     * Largest age of a signed delivery, in seconds.
     */
    private const SIGNATURE_TOLERANCE = 300;

    public function __construct(private readonly BankSyncService $sync)
    {
        //
    }

    /**
     * Whether a secret to check deliveries is configured at all.
     */
    public function configured(): bool
    {
        return filled(config('services.pluggy.webhook_token')) || filled(config('services.pluggy.webhook_signing_secret'));
    }

    /**
     * A delivery is accepted with the token the webhook was registered with
     * (X-Gamidas-Token or a bearer token) or with a valid Pluggy signature.
     */
    public function authentic(Request $request): bool
    {
        $token = (string) config('services.pluggy.webhook_token');
        $sent = (string) ($request->header('X-Gamidas-Token') ?? $request->bearerToken() ?? '');

        if ($token !== '' && $sent !== '' && hash_equals($token, $sent)) {
            return true;
        }

        $secret = (string) config('services.pluggy.webhook_signing_secret');
        $signature = (string) $request->header('X-Pluggy-Signature', '');

        return $secret !== '' && $signature !== '' && $this->validSignature($signature, $request->getContent(), $secret);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return string What was done: queued (a sync after the response), recorded or ignored.
     */
    public function handle(array $payload): string
    {
        $event = (string) ($payload['event'] ?? '');
        $itemId = $payload['itemId'] ?? null;

        if (! is_string($itemId) || $itemId === '' || strlen($itemId) > 64) {
            return 'ignored';
        }

        $connection = BankConnection::query()->firstWhere('item_hash', BankConnection::hashItem($itemId));

        if ($connection === null) {
            return 'ignored';
        }

        switch ($event) {
            case 'item/deleted':
                $connection->update([
                    'status' => 'DELETED',
                    'error_code' => null,
                    'error_message' => 'A conexão foi removida na Pluggy. Conecte o banco de novo.',
                ]);

                return 'recorded';

            case 'transactions/deleted':
                $this->sync->markDeleted($connection, $this->ids($payload));

                return 'recorded';

            case 'transactions/updated':
                $accountId = $payload['accountId'] ?? null;
                $ids = is_string($accountId) && $accountId !== '' ? [$accountId => $this->ids($payload)] : [];

                return $this->queue($connection, $ids);

            case 'item/error':
                $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
                $connection->update([
                    'error_code' => is_string($error['code'] ?? null) ? Str::limit($error['code'], 64, '') : $connection->error_code,
                    'error_message' => is_string($error['message'] ?? null) ? Str::limit($error['message'], 500, '') : $connection->error_message,
                ]);

                return $this->queue($connection);

            case 'transactions/created':
            case 'item/created':
            case 'item/updated':
            case 'item/login_succeeded':
            case 'item/waiting_user_input':
            case 'item/waiting_user_action':
                return $this->queue($connection);

            default:
                return 'ignored';
        }
    }

    /**
     * @param  array<string, list<string>>  $updatedIds
     */
    private function queue(BankConnection $connection, array $updatedIds = []): string
    {
        $connection->update(['sync_requested_at' => Date::now()]);

        SyncBankConnection::dispatchAfterResponse($connection->id, $updatedIds);

        return 'queued';
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return list<string>
     */
    private function ids(array $payload): array
    {
        $ids = is_array($payload['transactionIds'] ?? null) ? $payload['transactionIds'] : [];

        return array_values(array_slice(array_filter($ids, fn ($id) => is_string($id) && $id !== '' && strlen($id) <= 64), 0, 1000));
    }

    /**
     * Check "t=<unix>,v1=<hex>": an HMAC-SHA256 of "<t>.<raw body>" with the
     * signing secret, at most five minutes old.
     */
    private function validSignature(string $header, string $body, string $secret): bool
    {
        $parts = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key][] = $value;
        }

        $timestamp = $parts['t'][0] ?? '';

        if (! ctype_digit($timestamp) || abs(Date::now()->getTimestamp() - (int) $timestamp) > self::SIGNATURE_TOLERANCE) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        foreach ($parts['v1'] ?? [] as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
