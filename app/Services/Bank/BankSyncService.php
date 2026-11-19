<?php

namespace App\Services\Bank;

use App\Enums\BankAccountType;
use App\Models\BankAccount;
use App\Models\BankBill;
use App\Models\BankConnection;
use App\Models\BankInvestment;
use App\Models\BankTransaction;
use App\Services\Pluggy\PluggyClient;
use App\Services\Pluggy\PluggyException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Brings a connection's data from Pluggy: item status, accounts and cards,
 * card bills, investments and transactions. Idempotent: rows are matched by
 * Pluggy id, so syncing twice (webhook and scheduler) never duplicates.
 *
 * Transactions are read three ways, because card installments may arrive
 * with old dates and card purchases change after the bill closes:
 * everything created since the last sync, everything dated within the
 * refresh window, and the ids a webhook said were updated. The first sync
 * reads from the connection's import start.
 */
final class BankSyncService
{
    public function __construct(
        private readonly PluggyClient $pluggy,
        private readonly BankConnectionService $connections,
        private readonly TransactionClassifier $classifier,
        private readonly BankCatalogService $catalog,
        private readonly BankOffsetService $offsets,
    ) {
        //
    }

    /**
     * Sync a connection. Two syncs of the same connection never run at once;
     * when one is already running, this one only asks for another round.
     *
     * @param  array<string, list<string>>  $updatedIds  Transaction ids by Pluggy account id, from webhooks.
     * @return int|null New transactions imported, or null when another sync was running.
     */
    public function sync(BankConnection $connection, array $updatedIds = []): ?int
    {
        $lock = Cache::lock('bank-sync:'.$connection->id, 300);

        if (! $lock->get()) {
            $connection->update(['sync_requested_at' => Date::now()]);

            return null;
        }

        try {
            return $this->run($connection->fresh() ?? $connection, $updatedIds);
        } catch (PluggyException $exception) {
            $connection->update(['sync_error' => Str::limit($exception->getMessage(), 500, '')]);

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    /**
     * Mark transactions the bank deleted. They leave the inbox; one already
     * catalogued keeps its expense and is flagged so the owner can check it
     * (the bank often re-creates it with a new id, which inherits it).
     *
     * @param  list<string>  $pluggyIds
     */
    public function markDeleted(BankConnection $connection, array $pluggyIds): int
    {
        if ($pluggyIds === []) {
            return 0;
        }

        return BankTransaction::query()
            ->whereIn('bank_account_id', $connection->accounts()->select('id'))
            ->whereIn('pluggy_id', $pluggyIds)
            ->whereNull('removed_at')
            ->update(['removed_at' => Date::now()]);
    }

    /**
     * @param  array<string, list<string>>  $updatedIds
     */
    private function run(BankConnection $connection, array $updatedIds): int
    {
        $startedAt = Date::now();

        $this->connections->applyItem($connection, $this->pluggy->item($connection->item_id));

        $created = 0;

        foreach ($this->pluggy->accounts($connection->item_id) as $data) {
            $account = $this->upsertAccount($connection, $data);

            if ($account === null) {
                continue;
            }

            if ($account->isCreditCard()) {
                $this->syncBills($account);
            }

            $created += $this->syncTransactions($connection, $account, $updatedIds[$account->pluggy_id] ?? []);
        }

        $this->syncInvestments($connection);

        $connection->update([
            'synced_at' => $startedAt,
            'sync_requested_at' => null,
            'sync_error' => null,
        ]);

        return $created;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsertAccount(BankConnection $connection, array $data): ?BankAccount
    {
        $pluggyId = $data['id'] ?? null;
        $type = BankAccountType::tryFrom(strtoupper((string) ($data['type'] ?? '')));

        if (! is_string($pluggyId) || $pluggyId === '' || $type === null) {
            return null;
        }

        $credit = is_array($data['creditData'] ?? null) ? $data['creditData'] : [];
        $name = $data['marketingName'] ?? $data['name'] ?? null;

        $account = BankAccount::query()->firstOrNew(['pluggy_id' => $pluggyId]);

        if ($account->exists && $account->bank_connection_id !== $connection->id) {
            // Already imported through another connection: never move it.
            return null;
        }

        $account->fill([
            'bank_connection_id' => $connection->id,
            'user_id' => $connection->user_id,
            'type' => $type,
            'subtype' => $this->text($data['subtype'] ?? null, 32),
            'name' => $this->text($name, 160) ?? $type->label(),
            'number' => $this->text($data['number'] ?? null, 32),
            'balance_cents' => TransactionClassifier::cents($data['balance'] ?? 0),
            'currency_code' => $this->text($data['currencyCode'] ?? null, 3) ?? 'BRL',
            'credit_limit_cents' => isset($credit['creditLimit']) ? TransactionClassifier::cents($credit['creditLimit']) : null,
            'available_credit_cents' => isset($credit['availableCreditLimit']) ? TransactionClassifier::cents($credit['availableCreditLimit']) : null,
            'balance_close_date' => TransactionClassifier::date($credit['balanceCloseDate'] ?? null),
            'balance_due_date' => TransactionClassifier::date($credit['balanceDueDate'] ?? null),
        ]);
        $account->save();
        $account->setRelation('connection', $connection);

        return $account;
    }

    private function syncBills(BankAccount $account): void
    {
        foreach ($this->pluggy->bills($account->pluggy_id) as $data) {
            $pluggyId = $data['id'] ?? null;
            $dueDate = TransactionClassifier::date($data['dueDate'] ?? null);

            if (! is_string($pluggyId) || $pluggyId === '' || $dueDate === null) {
                continue;
            }

            $charges = 0;

            foreach (is_array($data['financeCharges'] ?? null) ? $data['financeCharges'] : [] as $charge) {
                $charges += is_array($charge) ? abs(TransactionClassifier::cents($charge['amount'] ?? 0)) : 0;
            }

            BankBill::query()->updateOrCreate(['pluggy_id' => $pluggyId], [
                'bank_account_id' => $account->id,
                'closing_date' => TransactionClassifier::date($data['billClosingDate'] ?? null),
                'due_date' => $dueDate,
                'total_cents' => TransactionClassifier::cents($data['totalAmount'] ?? 0),
                'minimum_payment_cents' => isset($data['minimumPaymentAmount']) ? TransactionClassifier::cents($data['minimumPaymentAmount']) : null,
                'finance_charges_cents' => $charges,
            ]);
        }
    }

    /**
     * Investment positions. A product the connection does not share (or the
     * plan does not include) is skipped without failing the sync.
     */
    private function syncInvestments(BankConnection $connection): void
    {
        try {
            $investments = $this->pluggy->investments($connection->item_id);
        } catch (PluggyException $exception) {
            if ($exception->status !== null && $exception->status >= 400 && $exception->status < 500) {
                Log::info('Pluggy investments unavailable', ['status' => $exception->status, 'code' => $exception->errorCode]);

                return;
            }

            throw $exception;
        }

        $seen = [];

        foreach ($investments as $data) {
            $pluggyId = $data['id'] ?? null;

            if (! is_string($pluggyId) || $pluggyId === '') {
                continue;
            }

            $seen[] = $pluggyId;

            BankInvestment::query()->updateOrCreate(['pluggy_id' => $pluggyId], [
                'bank_connection_id' => $connection->id,
                'user_id' => $connection->user_id,
                'name' => $this->text($data['name'] ?? null, 160) ?? 'Investimento',
                'type' => $this->text($data['type'] ?? null, 32),
                'subtype' => $this->text($data['subtype'] ?? null, 64),
                'status' => $this->text($data['status'] ?? null, 16),
                'balance_cents' => TransactionClassifier::cents($data['balance'] ?? $data['amount'] ?? 0),
                'invested_cents' => isset($data['amountOriginal']) ? TransactionClassifier::cents($data['amountOriginal']) : null,
                'rate' => $this->rate($data),
                'due_date' => TransactionClassifier::date($data['dueDate'] ?? null),
                'balance_at' => TransactionClassifier::date($data['date'] ?? null),
            ]);
        }

        // Positions fully withdrawn stop being listed by the bank.
        $connection->investments()->whereNotIn('pluggy_id', $seen)->delete();
    }

    /**
     * @param  list<string>  $updatedIds
     */
    private function syncTransactions(BankConnection $connection, BankAccount $account, array $updatedIds): int
    {
        $today = Date::today();
        $importFrom = $connection->import_from;
        $windowFrom = $today->subDays((int) config('bank.refresh_days'))->max($importFrom);

        /** @var array<string, array{0: array<string, mixed>, 1: bool}> $fetched Data and whether it may be older than the import start. */
        $fetched = [];
        $window = null;

        if ($connection->synced_at === null) {
            foreach ($this->pluggy->transactions($account->pluggy_id, ['dateFrom' => $importFrom->toDateString()]) as $data) {
                $this->collect($fetched, $data, false);
            }
        } else {
            // Created since the last sync, whatever their date (installments
            // of old purchases come with the purchase date).
            $createdFrom = $connection->synced_at->subDay()->utc()->format('Y-m-d\TH:i:s.v\Z');

            foreach ($this->pluggy->transactions($account->pluggy_id, ['createdAtFrom' => $createdFrom]) as $data) {
                $this->collect($fetched, $data, true);
            }

            $window = [];

            foreach ($this->pluggy->transactions($account->pluggy_id, ['dateFrom' => $windowFrom->toDateString()]) as $data) {
                $id = $this->collect($fetched, $data, false);

                if ($id !== null) {
                    $window[$id] = true;
                }
            }
        }

        foreach (array_chunk(array_values(array_unique($updatedIds)), 500) as $ids) {
            foreach ($this->pluggy->transactions($account->pluggy_id, ['ids' => implode(',', $ids)]) as $data) {
                $this->collect($fetched, $data, true);
            }
        }

        // Before importing, so a replacement imported now finds the
        // transaction it replaces already marked and inherits its decision.
        if ($window !== null && $connection->status === 'UPDATED') {
            $this->markVanished($account, $windowFrom, array_keys($window));
        }

        $created = [];

        foreach ($fetched as $pluggyId => [$data, $allowOld]) {
            $transaction = $this->import($connection, $account, $pluggyId, $data, $allowOld);

            if ($transaction !== null) {
                $created[] = $transaction;
            }
        }

        // Only now, with the whole batch stored, are the new ones handled:
        // a refund and its charge arriving together pair up whatever their
        // order, before an automatic rule could catalogue the charge.
        foreach ($created as $transaction) {
            $transaction->refresh();

            if ($transaction->isPending()) {
                $transaction->setRelation('account', $account);
                $this->catalog->onImported($transaction);
            }
        }

        // A pair whose side the bank removed (and did not re-create, which
        // would have inherited it) is undone: the other side is back to the
        // inbox.
        $this->offsets->releaseVanished($account);

        return count($created);
    }

    /**
     * @param  array<string, array{0: array<string, mixed>, 1: bool}>  $fetched
     * @param  array<string, mixed>  $data
     */
    private function collect(array &$fetched, array $data, bool $allowOld): ?string
    {
        $id = $data['id'] ?? null;

        if (! is_string($id) || $id === '' || strlen($id) > 64) {
            return null;
        }

        $fetched[$id] = [$data, $allowOld || ($fetched[$id][1] ?? false)];

        return $id;
    }

    /**
     * Create or refresh one transaction. Refreshing changes only what the
     * bank says (date, amount, status, bill...), never what the owner
     * decided. New transactions older than the import start are skipped,
     * except those created after the connection (installments).
     *
     * @param  array<string, mixed>  $data
     * @return BankTransaction|null The transaction created, if it is new.
     */
    private function import(BankConnection $connection, BankAccount $account, string $pluggyId, array $data, bool $allowOld): ?BankTransaction
    {
        $attributes = $this->classifier->classify($data, $account->type);
        $existing = BankTransaction::query()->firstWhere('pluggy_id', $pluggyId);

        if ($existing !== null) {
            if ($existing->bank_account_id === $account->id) {
                $existing->update([...$attributes, 'removed_at' => null]);
                $existing->setRelation('account', $account);

                // Pairs that arrived before they could be matched (or before
                // matching existed) are caught when they are read again.
                $this->offsets->autoPair($existing);
            }

            return null;
        }

        /** @var CarbonImmutable $date */
        $date = $attributes['date'];

        if ($attributes['amount_cents'] === 0 || (! $allowOld && $date->lt($connection->import_from))) {
            return null;
        }

        return BankTransaction::create([
            ...$attributes,
            'bank_account_id' => $account->id,
            'user_id' => $connection->user_id,
            'pluggy_id' => $pluggyId,
        ]);
    }

    /**
     * Pending card purchases inside the refresh window that the bank no
     * longer lists were replaced or cancelled (a lost "deleted" webhook).
     * Should one come back, the next sync clears the mark.
     *
     * @param  list<string>  $seen
     */
    private function markVanished(BankAccount $account, CarbonImmutable $windowFrom, array $seen): void
    {
        BankTransaction::query()
            ->where('bank_account_id', $account->id)
            ->where('bank_status', 'PENDING')
            ->whereNull('removed_at')
            ->where('date', '>=', $windowFrom->toDateString())
            ->whereNotIn('pluggy_id', $seen)
            ->update(['removed_at' => Date::now()]);
    }

    /**
     * A readable rate, e.g. "110% CDI" or "12,5% a.a.".
     *
     * @param  array<string, mixed>  $data
     */
    private function rate(array $data): ?string
    {
        $rate = $data['rate'] ?? $data['fixedAnnualRate'] ?? null;

        if (! is_numeric($rate)) {
            return null;
        }

        $value = rtrim(rtrim(number_format((float) $rate, 4, ',', ''), '0'), ',');
        $indexer = $this->text($data['rateType'] ?? null, 20);

        return Str::limit($value.'%'.($indexer !== null ? ' '.$indexer : ' a.a.'), 64, '');
    }

    private function text(mixed $value, int $limit): ?string
    {
        return is_string($value) && trim($value) !== '' ? Str::limit(trim($value), $limit, '') : null;
    }
}
