<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Posters, cast and synopses of what the household watches. Use the
    // "API Read Access Token" of a themoviedb.org account; without it the
    // watch history shows only what Jellyfin sends.
    'tmdb' => [
        'token' => env('TMDB_TOKEN'),
        'language' => env('TMDB_LANGUAGE', 'pt-BR'),
    ],

    // Open Finance through Pluggy (https://pluggy.ai). The client id and
    // secret come from the Pluggy dashboard (Applications); without them the
    // bank pages explain how to configure and nothing is fetched. Webhooks are
    // accepted with the token sent in the X-Gamidas-Token header (registered
    // by `php artisan bank:webhook`) or with a valid X-Pluggy-Signature when
    // the dashboard's signing secret (whsec_...) is configured.
    'pluggy' => [
        'client_id' => env('PLUGGY_CLIENT_ID'),
        'client_secret' => env('PLUGGY_CLIENT_SECRET'),
        'base_url' => env('PLUGGY_BASE_URL', 'https://api.pluggy.ai'),
        'webhook_token' => env('PLUGGY_WEBHOOK_TOKEN'),
        'webhook_signing_secret' => env('PLUGGY_WEBHOOK_SIGNING_SECRET'),
    ],

    // Each user's Steam library (games, playtime, achievements). The Web API
    // key comes from https://steamcommunity.com/dev/apikey; without it the
    // games page explains how to configure and nothing is fetched. Profiles
    // must show "Game details" publicly. Texts in STEAM_LANGUAGE (Steam's
    // language names, e.g. brazilian) and prices in STEAM_COUNTRY's currency.
    'steam' => [
        'key' => env('STEAM_API_KEY'),
        'language' => env('STEAM_LANGUAGE', 'brazilian'),
        'country' => env('STEAM_COUNTRY', 'br'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
