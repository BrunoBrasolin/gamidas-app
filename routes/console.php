<?php

use Illuminate\Support\Facades\Schedule;

// Materialize recurring expenses for the coming months. The command is
// idempotent, so running it more often than needed is harmless. The server
// cron fires schedule:run every 20 minutes, so the time must sit on that grid.
Schedule::command('expenses:generate')->dailyAt('00:20')->withoutOverlapping();

// Delete knowledge attachments removed from their page more than a day ago and
// files no attachment points to.
Schedule::command('knowledge:prune-attachments')->dailyAt('03:20')->withoutOverlapping();

// Bank data from Pluggy. Webhooks sync right away; this catches connections
// whose webhook was lost or failed, and refreshes the others every few hours.
Schedule::command('bank:sync')->cron('*/20 * * * *')->withoutOverlapping();

// Steam profiles, libraries and playtime of the connected accounts (each
// read again after an hour), plus the achievements of games played since.
Schedule::command('steam:sync')->cron('*/20 * * * *')->withoutOverlapping();
