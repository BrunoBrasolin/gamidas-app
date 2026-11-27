<?php

namespace App\Http\Controllers\Games;

use App\Enums\BacklogStatus;
use App\Enums\TierListKind;
use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Presenters\SteamPresenter;
use App\Http\Requests\Games\BacklogAddRequest;
use App\Http\Requests\Games\BacklogOrderRequest;
use App\Http\Requests\Games\BacklogStatusRequest;
use App\Http\Requests\Tiers\TierListRequest;
use App\Http\Requests\Tiers\TierRequest;
use App\Models\SteamBacklogEntry;
use App\Models\SteamGame;
use App\Services\Steam\SteamListsService;
use App\Services\Steam\SteamStats;
use App\Services\TierListShareService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The user's tier list and "para zerar" list of their Steam games. Both
 * need the Steam account connected to be shown or changed; they are kept
 * when it is disconnected.
 */
class GameListsController extends Controller
{
    use InteractsWithHousehold;

    public function __construct(private readonly SteamListsService $lists)
    {
        //
    }

    public function tiers(Request $request, SteamStats $stats, TierListShareService $shares): Response|RedirectResponse
    {
        $user = $this->user($request);
        $account = $user->steamAccount;

        if ($account === null) {
            return to_route('games.index');
        }

        return Inertia::render('games/tiers', [
            'tiers' => $this->lists->tiers($user),
            'library' => $stats->library($account)->map(fn (array $game) => SteamPresenter::libraryGame($game))->values(),
            'shareUrl' => $shares->url($user, TierListKind::Games),
        ]);
    }

    public function updateTiers(TierListRequest $request): RedirectResponse
    {
        $this->lists->setTiers($this->user($request), $request->tiers());

        return back();
    }

    /**
     * Put one game in a tier (at the end) or take it off the tier list.
     */
    public function tier(TierRequest $request, SteamGame $game): RedirectResponse
    {
        Gate::authorize('view', $game);

        $this->lists->setTier($this->user($request), $game, $request->tier());

        return back();
    }

    public function backlog(Request $request, SteamStats $stats): Response|RedirectResponse
    {
        $user = $this->user($request);
        $account = $user->steamAccount;

        if ($account === null) {
            return to_route('games.index');
        }

        $entries = $this->lists->backlog($user);
        $games = $this->lists->libraryGames($user, array_values($entries->map(fn (SteamBacklogEntry $entry) => $entry->app_id)->all()));
        $tiers = $this->lists->tierByApp($user);

        return Inertia::render('games/backlog', [
            'entries' => $entries->map(fn (SteamBacklogEntry $entry) => SteamPresenter::backlogEntry(
                $entry,
                $this->lists->listGame($entry->app_id, $entry->name, $games->get($entry->app_id)),
                $tiers[$entry->app_id] ?? null,
            ))->values(),
            'library' => $stats->library($account)->map(fn (array $game) => SteamPresenter::libraryGame($game))->values(),
        ]);
    }

    public function addToBacklog(Request $request, SteamGame $game): RedirectResponse
    {
        Gate::authorize('view', $game);

        $entry = $this->lists->addToBacklog($this->user($request), $game);

        $this->toast($entry->wasRecentlyCreated ? "{$game->name} entrou na lista para zerar." : "{$game->name} já está na lista para zerar.");

        return back();
    }

    /**
     * Add the games picked in the library, in the order they were picked.
     */
    public function addManyToBacklog(BacklogAddRequest $request): RedirectResponse
    {
        $user = $this->user($request);
        $ids = $request->gameIds();
        $games = $user->steamAccount?->games()->whereIn('id', $ids)->get()->keyBy('id') ?? collect();

        if ($games->count() !== count($ids)) {
            throw ValidationException::withMessages(['game_ids' => 'Algum jogo não está na sua biblioteca da Steam.']);
        }

        $changed = $this->lists->addManyToBacklog(
            $user,
            array_map(fn (int $id) => $games->get($id), $ids),
            $request->finished(),
            $request->finishedOn(),
        );

        $this->toast(match (true) {
            $changed === 0 => 'Esses jogos já estavam na lista para zerar.',
            $request->finished() => $changed === 1 ? '1 jogo marcado como zerado.' : "{$changed} jogos marcados como zerados.",
            default => $changed === 1 ? '1 jogo entrou na lista para zerar.' : "{$changed} jogos entraram na lista para zerar.",
        });

        return back();
    }

    public function updateBacklog(BacklogStatusRequest $request, SteamBacklogEntry $entry): RedirectResponse
    {
        Gate::authorize('update', $entry);

        $this->lists->setStatus($entry, $request->status());

        if ($request->status() === BacklogStatus::Completed) {
            $this->toast("{$entry->name} zerado!");
        }

        return back();
    }

    public function orderBacklog(BacklogOrderRequest $request): RedirectResponse
    {
        $this->lists->reorderQueue($this->user($request), $request->ids());

        return back();
    }

    public function removeFromBacklog(SteamBacklogEntry $entry): RedirectResponse
    {
        Gate::authorize('delete', $entry);

        $entry->delete();

        $this->toast("{$entry->name} saiu da lista para zerar.");

        return back();
    }
}
