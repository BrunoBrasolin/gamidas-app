<?php

namespace App\Http\Controllers\Watched;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Presenters\WatchPresenter;
use App\Models\WatchProgress;
use App\Services\Tmdb\MediaDetails;
use App\Services\Tmdb\TmdbClient;
use App\Services\WatchHistoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Items the household started and has not finished ("Continuar assistindo").
 */
class WatchProgressController extends Controller
{
    use InteractsWithHousehold;

    public function show(WatchProgress $progress, MediaDetails $details, TmdbClient $tmdb): Response
    {
        Gate::authorize('view', $progress);

        return Inertia::render('watched/show', [
            'item' => null,
            'progress' => WatchPresenter::progress($progress),
            'details' => $details->for($progress),
            'tmdb_configured' => $tmdb->configured(),
        ]);
    }

    public function destroy(WatchProgress $progress, WatchHistoryService $history): RedirectResponse
    {
        Gate::authorize('delete', $progress);

        $history->deleteProgress($progress);

        $this->toast('Removido de "Continuar assistindo".');

        return to_route('watched.index');
    }
}
