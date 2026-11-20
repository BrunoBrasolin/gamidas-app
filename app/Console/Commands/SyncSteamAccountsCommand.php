<?php

namespace App\Console\Commands;

use App\Models\SteamAccount;
use App\Services\Steam\SteamClient;
use App\Services\Steam\SteamException;
use App\Services\Steam\SteamSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

class SyncSteamAccountsCommand extends Command
{
    /**
     * Minutes after which an account's profile and library are read again.
     */
    public const STALE_MINUTES = 55;

    /**
     * @var string
     */
    protected $signature = 'steam:sync {--all : Sync every account, not only the stale ones}';

    /**
     * @var string
     */
    protected $description = 'Fetch Steam profiles, libraries, playtime and achievements (idempotent)';

    public function handle(SteamClient $steam, SteamSyncService $sync): int
    {
        if (! $steam->configured()) {
            return self::SUCCESS;
        }

        $stale = Date::now()->subMinutes(self::STALE_MINUTES);
        $accounts = SteamAccount::query()->orderBy('id')->get();
        $failed = 0;
        $synced = 0;
        $achievements = 0;

        foreach ($accounts as $account) {
            try {
                if ($this->option('all') || $account->checked_at === null || $account->checked_at->lessThan($stale)) {
                    $sync->sync($account);
                    $synced++;
                }

                if ($account->public_games) {
                    $achievements += $sync->syncAchievements($account);
                }
            } catch (SteamException $exception) {
                $failed++;
                $this->components->warn("Conta Steam {$account->id}: {$exception->getMessage()}");
            }
        }

        $this->components->info("{$synced} conta(s) Steam sincronizada(s), conquistas de {$achievements} jogo(s) buscadas.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
