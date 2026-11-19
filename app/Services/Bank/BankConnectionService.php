<?php

namespace App\Services\Bank;

use App\Models\BankConnection;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Pluggy\PluggyClient;
use App\Services\Pluggy\PluggyException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Connects and disconnects members' banks (Pluggy items) and keeps each
 * connection's status: login errors, expired consent and the like, which
 * ask the member to connect again.
 */
final class BankConnectionService
{
    /**
     * Pluggy's connector that mirrors banks connected at meu.pluggy.ai
     * (personal use). Its items cannot be refreshed through the API: they
     * follow the daily update of Meu Pluggy and are renewed there.
     */
    public const MEU_PLUGGY_CONNECTOR = 200;

    public function __construct(
        private readonly PluggyClient $pluggy,
        private readonly AuditLogger $audit,
    ) {
        //
    }

    /**
     * The pseudonymous id Pluggy keeps for the member (never their name or
     * e-mail), used to check that an item was created for them.
     */
    public function clientUserId(User $user): string
    {
        return 'gamidas-'.substr(hash_hmac('sha256', 'bank-user:'.$user->id, (string) config('app.key')), 0, 32);
    }

    /**
     * Attach a Pluggy item to the member: one created by the Connect widget,
     * or one whose id they copied from the Pluggy dashboard (Meu Pluggy). An
     * item created for someone else, or already connected by another member,
     * is refused.
     */
    public function connect(User $user, string $itemId): BankConnection
    {
        $itemId = trim($itemId);

        $existing = BankConnection::query()->firstWhere('item_hash', BankConnection::hashItem($itemId));

        if ($existing !== null && $existing->user_id !== $user->id) {
            throw ValidationException::withMessages(['item_id' => 'Esta conexão já foi adicionada por outro membro.']);
        }

        try {
            $item = $this->pluggy->item($itemId);
        } catch (PluggyException $exception) {
            throw ValidationException::withMessages([
                'item_id' => $exception->isNotFound() ? 'A Pluggy não encontrou uma conexão com este ID.' : $exception->getMessage(),
            ]);
        }

        $owner = $item['clientUserId'] ?? null;

        if (is_string($owner) && $owner !== '' && $owner !== $this->clientUserId($user)) {
            throw ValidationException::withMessages(['item_id' => 'Esta conexão foi criada para outra pessoa.']);
        }

        return DB::transaction(function () use ($user, $itemId, $item, $existing) {
            $connection = $existing ?? new BankConnection([
                'user_id' => $user->id,
                'item_id' => $itemId,
                'item_hash' => BankConnection::hashItem($itemId),
                'import_from' => Date::today()->subDays((int) config('bank.initial_days')),
            ]);

            $this->applyItem($connection, $item);

            if ($existing === null) {
                $this->audit->record('bank.connected', $connection, $user, $user->householdId(), null, [
                    'connector' => $connection->connector_name,
                ]);
            }

            return $connection;
        });
    }

    /**
     * Copy the item's state from Pluggy: bank, status, error and consent.
     *
     * @param  array<string, mixed>  $item
     */
    public function applyItem(BankConnection $connection, array $item): void
    {
        $connector = is_array($item['connector'] ?? null) ? $item['connector'] : [];
        $error = is_array($item['error'] ?? null) ? $item['error'] : [];

        $connection->fill([
            'connector_id' => is_numeric($connector['id'] ?? null) ? (int) $connector['id'] : $connection->connector_id,
            'connector_name' => $this->text($connector['name'] ?? null, 120) ?? $connection->connector_name ?? 'Banco',
            'connector_image_url' => $this->url($connector['imageUrl'] ?? null) ?? $connection->connector_image_url,
            'connector_color' => $this->color($connector['primaryColor'] ?? null) ?? $connection->connector_color,
            'status' => $this->text($item['status'] ?? null, 32) ?? 'UPDATING',
            'execution_status' => $this->text($item['executionStatus'] ?? null, 64),
            'error_code' => $this->text($error['code'] ?? null, 64),
            'error_message' => $this->text($error['message'] ?? null, 500),
            'consent_expires_at' => $this->timestamp($item['consentExpiresAt'] ?? null),
            'bank_updated_at' => $this->timestamp($item['lastUpdatedAt'] ?? null) ?? $connection->bank_updated_at,
        ]);

        $connection->save();
    }

    /**
     * Revoke the connection at Pluggy and delete everything imported through
     * it. Expenses and personal entries already catalogued stay: they are the
     * household's and the member's records, not the bank's.
     *
     * @return bool Whether Pluggy confirmed the revocation.
     */
    public function disconnect(BankConnection $connection, User $actor): bool
    {
        $revoked = true;

        try {
            $this->pluggy->deleteItem($connection->item_id);
        } catch (PluggyException) {
            $revoked = false;
        }

        DB::transaction(function () use ($connection, $actor, $revoked) {
            $this->audit->record('bank.disconnected', $connection, $actor, $actor->householdId(), [
                'connector' => $connection->connector_name,
            ], ['revoked_at_pluggy' => $revoked]);

            $connection->delete();
        });

        return $revoked;
    }

    public function isMeuPluggy(BankConnection $connection): bool
    {
        return $connection->connector_id === self::MEU_PLUGGY_CONNECTOR;
    }

    private function text(mixed $value, int $limit): ?string
    {
        return is_string($value) && trim($value) !== '' ? Str::limit(trim($value), $limit, '') : null;
    }

    private function url(mixed $value): ?string
    {
        return is_string($value) && str_starts_with($value, 'https://') && strlen($value) <= 500 ? $value : null;
    }

    private function color(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = ltrim(trim($value), '#');

        return preg_match('/^[0-9a-fA-F]{6}$/', $value) === 1 ? '#'.$value : null;
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->setTimezone((string) config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}
