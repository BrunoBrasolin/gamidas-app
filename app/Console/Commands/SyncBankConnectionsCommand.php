<?php

namespace App\Console\Commands;

use App\Models\BankConnection;
use App\Services\Bank\BankSyncService;
use App\Services\Pluggy\PluggyClient;
use App\Services\Pluggy\PluggyException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;

class SyncBankConnectionsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'bank:sync {--all : Sync every connection, not only the pending or stale ones}';

    /**
     * @var string
     */
    protected $description = 'Fetch accounts, bills, investments and transactions from Pluggy (idempotent)';

    public function handle(PluggyClient $pluggy, BankSyncService $sync): int
    {
        if (! $pluggy->configured()) {
            return self::SUCCESS;
        }

        $stale = Date::now()->subHours((int) config('bank.stale_hours'));

        $connections = BankConnection::query()
            ->where('status', '!=', 'DELETED')
            ->unless($this->option('all'), fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereNotNull('sync_requested_at')
                ->orWhereNull('synced_at')
                ->orWhere('synced_at', '<', $stale)))
            ->orderBy('id')
            ->get();

        $created = 0;
        $failed = 0;

        foreach ($connections as $connection) {
            try {
                $created += (int) $sync->sync($connection);
            } catch (PluggyException $exception) {
                $failed++;
                $this->components->warn("Conexão {$connection->id}: {$exception->getMessage()}");
            }
        }

        $this->components->info("{$connections->count()} conexão(ões) sincronizada(s), {$created} transação(ões) nova(s).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
