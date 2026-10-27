<?php

namespace App\Http\Controllers\Watched;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Models\WatchedItem;
use App\Services\WatchHistoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class WatchedItemController extends Controller
{
    use InteractsWithHousehold;

    /** Session key holding a freshly issued token, shown once. */
    public const NEW_TOKEN = 'watched.jellyfin_token';

    public function __construct(private readonly WatchHistoryService $history)
    {
        //
    }

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $years = $this->history->years($user);
        $year = in_array($request->integer('year'), $years, true) ? $request->integer('year') : $years[0];
        $items = $this->history->itemsOfYear($user, $year);
        $connection = $user->jellyfinConnection;

        return Inertia::render('watched/index', [
            'year' => $year,
            'years' => $years,
            'items' => $items->map(fn (WatchedItem $item) => [
                'id' => $item->id,
                'type' => $item->type->value,
                'title' => $item->title,
                'series_name' => $item->series_name,
                'season_number' => $item->season_number,
                'episode_number' => $item->episode_number,
                'year' => $item->year,
                'runtime_seconds' => $item->runtime_seconds,
                'watched_at' => $item->watched_at->toIso8601String(),
                'watched_on' => $item->watched_on->toDateString(),
            ])->values(),
            'stats' => $this->history->stats($items),
            'jellyfin' => [
                'connected' => $connection !== null,
                'last_received_at' => $connection?->last_received_at?->toIso8601String(),
                'webhook_url' => route('webhooks.jellyfin'),
                'new_token' => $request->session()->get(self::NEW_TOKEN),
            ],
        ]);
    }

    public function destroy(WatchedItem $item): RedirectResponse
    {
        Gate::authorize('delete', $item);

        $this->history->delete($item);

        $this->toast('Item removido do histórico.');

        return back();
    }
}
