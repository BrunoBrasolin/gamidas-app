<?php

namespace App\Http\Controllers\Watched;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Services\WatchHistoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Issues and revokes the household's Jellyfin webhook token; any member may.
 * A new token is flashed to the session so the page shows it once.
 */
class JellyfinConnectionController extends Controller
{
    use InteractsWithHousehold;

    public function __construct(private readonly WatchHistoryService $history)
    {
        //
    }

    public function store(Request $request): RedirectResponse
    {
        $token = $this->history->issueToken($this->household($request));

        $request->session()->flash(WatchedItemController::NEW_TOKEN, $token);
        $this->toast('Token gerado. Copie agora: ele não será mostrado de novo.');

        return to_route('watched.index');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->history->revokeToken($this->household($request));

        $this->toast('Jellyfin desconectado.');

        return to_route('watched.index');
    }
}
