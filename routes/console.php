<?php

use Illuminate\Support\Facades\Schedule;

// Materialize recurring expenses for the coming months. The command is
// idempotent, so running it more often than needed is harmless.
Schedule::command('expenses:generate')->dailyAt('00:10')->withoutOverlapping();
