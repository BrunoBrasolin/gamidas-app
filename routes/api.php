<?php

use App\Http\Controllers\Watched\JellyfinWebhookController;
use Illuminate\Support\Facades\Route;

// Machine-to-machine endpoints, without session or CSRF. Each one
// authenticates the caller with its own token.
Route::post('webhooks/jellyfin', JellyfinWebhookController::class)
    ->middleware('throttle:webhooks')
    ->name('webhooks.jellyfin');
