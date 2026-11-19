<?php

namespace App\Jobs;

use App\Models\BankConnection;
use App\Services\Bank\BankSyncService;
use App\Services\Pluggy\PluggyException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sync one bank connection. Webhooks dispatch it after the response is sent
 * (the hosting has no queue worker, and Pluggy wants an answer within 10
 * seconds). A failure leaves the connection's sync request in place, so the
 * scheduled `bank:sync` tries again.
 */
class SyncBankConnection
{
    use Dispatchable, Queueable;

    /**
     * @param  array<string, list<string>>  $updatedIds  Transaction ids by Pluggy account id.
     */
    public function __construct(public int $connectionId, public array $updatedIds = [])
    {
        //
    }

    public function handle(BankSyncService $sync): void
    {
        $connection = BankConnection::query()->find($this->connectionId);

        if ($connection === null) {
            return;
        }

        try {
            $sync->sync($connection, $this->updatedIds);
        } catch (PluggyException) {
            // Recorded in the connection's sync_error; retried by bank:sync.
        }
    }
}
