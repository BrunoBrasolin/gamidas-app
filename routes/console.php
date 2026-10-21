<?php

use Illuminate\Support\Facades\Schedule;

// Materialize recurring expenses for the coming months. The command is
// idempotent, so running it more often than needed is harmless. The server
// cron fires schedule:run every 20 minutes, so the time must sit on that grid.
Schedule::command('expenses:generate')->dailyAt('00:20')->withoutOverlapping();
