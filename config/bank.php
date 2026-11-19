<?php

return [

    /*
    |--------------------------------------------------------------------------
    | First Import
    |--------------------------------------------------------------------------
    |
    | When a bank is connected, transactions of this many days back are
    | imported into the inbox. Pluggy keeps up to a year, but a year of
    | movements would bury the inbox.
    |
    */

    'initial_days' => (int) env('BANK_INITIAL_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Refresh Window
    |--------------------------------------------------------------------------
    |
    | Every sync reads again the transactions of the last days, so status
    | changes (a card purchase moving to its closed bill) and transactions the
    | bank replaced with a new id are picked up even if a webhook is lost.
    |
    */

    'refresh_days' => 45,

    /*
    |--------------------------------------------------------------------------
    | Scheduled Sync
    |--------------------------------------------------------------------------
    |
    | Webhooks trigger a sync right away. As a fallback, `bank:sync` (on the
    | scheduler) also syncs connections not synced for this many hours.
    |
    */

    'stale_hours' => (int) env('BANK_STALE_HOURS', 6),

    /*
    |--------------------------------------------------------------------------
    | Pluggy Connect
    |--------------------------------------------------------------------------
    |
    | The widget script, loaded from Pluggy's CDN only when a member opens it.
    |
    */

    'connect_script' => 'https://cdn.pluggy.ai/pluggy-connect/v2.8.2/pluggy-connect.js',

];
