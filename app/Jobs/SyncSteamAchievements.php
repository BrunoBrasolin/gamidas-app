<?php

namespace App\Jobs;

use App\Models\SteamAccount;
use App\Services\Steam\SteamException;
use App\Services\Steam\SteamSyncService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fetch the achievements of a few games of an account. Dispatched after the
 * response when the account is connected or synced by hand (the hosting has
 * no queue worker); the scheduled `steam:sync` fetches the rest.
 */
class SyncSteamAchievements
{
    use Dispatchable, Queueable;

    /**
     * Games fetched after a request: fewer than a scheduled run, so the
     * process ends well within the hosting's time limit.
     */
    public const GAMES = 15;

    public function __construct(public int $accountId)
    {
        //
    }

    public function handle(SteamSyncService $sync): void
    {
        $account = SteamAccount::query()->find($this->accountId);

        if ($account === null || ! $account->public_games) {
            return;
        }

        try {
            $sync->syncAchievements($account, self::GAMES);
        } catch (SteamException) {
            // Retried by steam:sync.
        }
    }
}
