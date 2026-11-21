<?php

namespace App\Http\Controllers\Games;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Presenters\SteamPresenter;
use App\Models\SteamAchievement;
use App\Models\SteamGame;
use App\Models\SteamWishlistItem;
use App\Services\Steam\SteamClient;
use App\Services\Steam\SteamException;
use App\Services\Steam\SteamFriends;
use App\Services\Steam\SteamStats;
use App\Services\Steam\SteamStoreDetails;
use App\Services\Steam\SteamSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in user's Steam library: profile, totals, playtime history and
 * every game; each game's page; the wishlist and the friends list. Private
 * to the user who connected the account.
 *
 * Pages never wait for Steam: whatever needs a call to Steam (a game's store
 * page and fresh achievements, the friends list) is a deferred prop, loaded
 * right after the page shows what is stored.
 */
class GameLibraryController extends Controller
{
    use InteractsWithHousehold;

    private const TOP_GAMES = 10;

    public function index(Request $request, SteamClient $steam, SteamStats $stats): Response
    {
        $account = $this->user($request)->steamAccount;

        if ($account === null) {
            return Inertia::render('games/index', [
                'configured' => $steam->configured(),
                'account' => null,
            ]);
        }

        $games = $stats->library($account);

        return Inertia::render('games/index', [
            'configured' => $steam->configured(),
            'account' => SteamPresenter::account($account),
            'summary' => $stats->summary($games),
            'games' => $games->map(fn (array $game) => SteamPresenter::libraryGame($game))->values(),
            'recent' => $games->filter(fn (array $game) => $game['playtime_2weeks_minutes'] > 0)
                ->sortByDesc('playtime_2weeks_minutes')
                ->map(fn (array $game) => SteamPresenter::libraryGame($game))
                ->values(),
            'top' => $games->filter(fn (array $game) => $game['playtime_minutes'] > 0)
                ->take(self::TOP_GAMES)
                ->map(fn (array $game) => SteamPresenter::libraryGame($game))
                ->values(),
            'months' => $stats->months($account),
            'days' => $stats->days($account),
            'history_since' => $stats->historySince($account),
        ]);
    }

    /**
     * The wishlist in the user's order on Steam (unranked games last, newest
     * first), with prices and discounts as of the last sync.
     */
    public function wishlist(Request $request, SteamStats $stats): Response|RedirectResponse
    {
        $account = $this->user($request)->steamAccount;

        if ($account === null) {
            return to_route('games.index');
        }

        $items = $account->wishlistItems()->get()->sort(fn (SteamWishlistItem $a, SteamWishlistItem $b) => [$a->priority === 0, $a->priority, -($a->added_at?->getTimestamp() ?? 0)]
            <=> [$b->priority === 0, $b->priority, -($b->added_at?->getTimestamp() ?? 0)])->values();
        $owned = $account->games()->inLibrary()->whereIn('app_id', $items->pluck('app_id')->all())->pluck('id', 'app_id');

        return Inertia::render('games/wishlist', [
            'account' => SteamPresenter::account($account),
            'summary' => $stats->wishlistSummary($items),
            'items' => $items->map(fn (SteamWishlistItem $item) => SteamPresenter::wishlistItem($item, $owned->get($item->app_id)))->values(),
        ]);
    }

    /**
     * The friends with what they are playing now. The list comes from Steam
     * (cached for a few minutes), so it is deferred. A friend playing a game
     * of the user's library links to its page.
     */
    public function friends(Request $request, SteamFriends $friends): Response|RedirectResponse
    {
        $account = $this->user($request)->steamAccount;

        if ($account === null) {
            return to_route('games.index');
        }

        return Inertia::render('games/friends', [
            'list' => Inertia::defer(function () use ($account, $friends) {
                $list = $friends->of($account);
                $owned = $account->games()->inLibrary()->pluck('id', 'app_id');

                return [
                    ...$list,
                    'friends' => array_map(fn (array $friend) => [
                        ...$friend,
                        'my_game_id' => $friend['playing'] !== null && $friend['playing']['app_id'] !== null
                            ? $owned->get($friend['playing']['app_id'])
                            : null,
                    ], $list['friends']),
                ];
            }),
        ]);
    }

    /**
     * The game's page: what is stored shows at once; the store page and the
     * achievements and stats (fetched from Steam when never fetched or when
     * the game was played since) come as deferred props, each in its own
     * request.
     */
    public function show(SteamGame $game, SteamClient $steam, SteamSyncService $sync, SteamStats $stats, SteamStoreDetails $store): Response
    {
        Gate::authorize('view', $game);

        $account = $game->account;

        return Inertia::render('games/show', [
            'game' => SteamPresenter::gameDetails($game),
            'months' => $stats->months($account, 12, $game),
            'store' => Inertia::defer(fn () => $store->for($game->app_id), 'store'),
            'progress' => Inertia::defer(function () use ($steam, $sync, $account, $game) {
                $error = null;

                if ($steam->configured() && $account->public_games && $game->achievementsStale()) {
                    try {
                        $sync->syncGameAchievements($account, $game);
                    } catch (SteamException $exception) {
                        $error = $exception->getMessage();
                    }
                }

                return [
                    'achievements_total' => $game->achievements_total,
                    'achievements_unlocked' => $game->achievements_unlocked,
                    'fetched' => $game->achievements_synced_at !== null,
                    'achievements' => $game->achievements()
                        ->orderByDesc('achieved')->orderByDesc('unlocked_at')->orderBy('position')->get()
                        ->map(fn (SteamAchievement $achievement) => SteamPresenter::achievement($achievement))
                        ->values(),
                    'stats' => $game->stats ?? [],
                    'error' => $error,
                ];
            }, 'progress'),
        ]);
    }
}
