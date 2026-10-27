<?php

namespace App\Http\Controllers\Watched;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Presenters\WatchPresenter;
use App\Models\WatchedItem;
use App\Models\WatchProgress;
use App\Services\Tmdb\MediaDetails;
use App\Services\Tmdb\TmdbClient;
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
        $household = $this->household($request);
        $years = $this->history->years($household);
        $year = in_array($request->integer('year'), $years, true) ? $request->integer('year') : $years[0];
        $items = $this->history->itemsOfYear($household, $year);
        $connection = $household->jellyfinConnection;

        return Inertia::render('watched/index', [
            'year' => $year,
            'years' => $years,
            'items' => $items->map(fn (WatchedItem $item) => WatchPresenter::item($item))->values(),
            'in_progress' => $this->history->inProgress($household)
                ->map(fn (WatchProgress $progress) => WatchPresenter::progress($progress))->values(),
            'series' => $this->history->seriesInProgress($household)
                ->map(fn (WatchedItem $item) => WatchPresenter::item($item))->values(),
            'stats' => $this->history->stats($items),
            'jellyfin' => [
                'connected' => $connection !== null,
                'last_received_at' => $connection?->last_received_at?->toIso8601String(),
                'webhook_url' => route('webhooks.jellyfin'),
                'new_token' => $request->session()->get(self::NEW_TOKEN),
            ],
        ]);
    }

    /**
     * The item's page, with its poster, synopsis and cast from TMDB.
     */
    public function show(WatchedItem $item, MediaDetails $details, TmdbClient $tmdb): Response
    {
        Gate::authorize('view', $item);

        return Inertia::render('watched/show', [
            'item' => WatchPresenter::item($item),
            'progress' => null,
            'details' => $details->for($item),
            'tmdb_configured' => $tmdb->configured(),
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
