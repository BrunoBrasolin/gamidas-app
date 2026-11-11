<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public pages cache
    |--------------------------------------------------------------------------
    |
    | Shared hosting caps how many MySQL connections an account may open per
    | hour, and every PHP request that touches the database opens one. The
    | public pages of the attention game (room info, live ranking, projector)
    | are therefore served from a file cache, which costs no connection, and
    | only the writes (joining, answering) reach the database.
    |
    */

    'cache_store' => env('ATTENTION_CACHE_STORE', 'file'),

    // Seconds a live ranking snapshot is served as is. Later requests still get
    // the old snapshot while a single one of them rebuilds it.
    'snapshot_ttl' => (int) env('ATTENTION_SNAPSHOT_TTL', 20),

    // How long an old snapshot may still be served while it is rebuilt.
    'snapshot_stale_ttl' => 900,

    // Seconds the room info (title, open or closed) and "no such room" answers
    // are remembered. Closing, reopening or deleting a room forgets them at once.
    'room_ttl' => 120,

];
