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
 * The signed-in user's Steam library: profile, totals, playtime history,
 * recent achievements and every game; each game's page; the wishlist and
 * the friends list. Private to the user who connected the account.
 */
class GameLibraryController extends Controller
{
    use InteractsWithHousehold;

    private const TOP_GAMES = 10;

    public function index(Request $request, SteamClient $steam, SteamStats $stats, SteamSyncService $sync): Response
    {
        $account = $this->user($request)->steamAccount;

        if ($account === null) {
            return Inertia::render('games/index', [
                'configured' => $steam->configured(),
                'account' => null,
            ]);
        }

        $games = $account->games()->inLibrary()->orderByDesc('playtime_minutes')->orderBy('name')->get();

        return Inertia::render('games/index', [
            'configured' => $steam->configured(),
            'account' => SteamPresenter::account($account, $sync->pendingAchievements($account)),
            'summary' => $stats->summary($games),
            'games' => $games->map(fn (SteamGame $game) => SteamPresenter::game($game))->values(),
            'recent' => $games->filter(fn (SteamGame $game) => $game->playtime_2weeks_minutes > 0)
                ->sortByDesc('playtime_2weeks_minutes')
                ->map(fn (SteamGame $game) => SteamPresenter::game($game))
                ->values(),
            'top' => $games->filter(fn (SteamGame $game) => $game->playtime_minutes > 0)
                ->take(self::TOP_GAMES)
                ->map(fn (SteamGame $game) => SteamPresenter::game($game))
                ->values(),
            'months' => $stats->months($account),
            'days' => $stats->days($account),
            'history_since' => $stats->historySince($account),
            'recent_achievements' => $stats->recentAchievements($account)
                ->map(fn (SteamAchievement $achievement) => SteamPresenter::achievement($achievement, withGame: true))
                ->values(),
        ]);
    }

    /**
     * The wishlist in the user's order on Steam (unranked games last, newest
     * first), with prices and discounts as of the last sync.
     */
    public function wishlist(Request $request, SteamSyncService $sync, SteamStats $stats): Response|RedirectResponse
    {
        $account = $this->user($request)->steamAccount;

        if ($account === null) {
            return to_route('games.index');
        }

        $items = $account->wishlistItems()->get()->sort(fn (SteamWishlistItem $a, SteamWishlistItem $b) => [$a->priority === 0, $a->priority, -($a->added_at?->getTimestamp() ?? 0)]
            <=> [$b->priority === 0, $b->priority, -($b->added_at?->getTimestamp() ?? 0)])->values();
        $owned = $account->games()->inLibrary()->whereIn('app_id', $items->pluck('app_id')->all())->pluck('id', 'app_id');

        return Inertia::render('games/wishlist', [
            'account' => SteamPresenter::account($account, $sync->pendingAchievements($account)),
            'summary' => $stats->wishlistSummary($items),
            'items' => $items->map(fn (SteamWishlistItem $item) => SteamPresenter::wishlistItem($item, $owned->get($item->app_id)))->values(),
        ]);
    }

    /**
     * The friends with what they are playing now, read from Steam (cached for
     * a few minutes). A friend playing a game of the user's library links to
     * its page.
     */
    public function friends(Request $request, SteamFriends $friends, SteamSyncService $sync): Response|RedirectResponse
    {
        $account = $this->user($request)->steamAccount;

        if ($account === null) {
            return to_route('games.index');
        }

        $list = $friends->of($account);
        $owned = $account->games()->inLibrary()->pluck('id', 'app_id');

        return Inertia::render('games/friends', [
            'account' => SteamPresenter::account($account, $sync->pendingAchievements($account)),
            'friends' => array_map(fn (array $friend) => [
                ...$friend,
                'my_game_id' => $friend['playing'] !== null && $friend['playing']['app_id'] !== null
                    ? $owned->get($friend['playing']['app_id'])
                    : null,
            ], $list['friends']),
            'private' => $list['private'],
            'error' => $list['error'],
        ]);
    }

    /**
     * The game's page: playtime, achievements and the store page. Its
     * achievements are fetched on the way when they were never fetched or
     * the game was played since.
     */
    public function show(SteamGame $game, SteamClient $steam, SteamSyncService $sync, SteamStats $stats, SteamStoreDetails $store): Response
    {
        Gate::authorize('view', $game);

        $account = $game->account;
        $error = null;

        if ($steam->configured() && $account->public_games && $game->achievementsStale()) {
            try {
                $sync->syncGameAchievements($account, $game);
            } catch (SteamException $exception) {
                $error = $exception->getMessage();
            }
        }

        $achievements = $game->achievements()->orderByDesc('achieved')->orderByDesc('unlocked_at')->orderBy('position')->get();

        return Inertia::render('games/show', [
            'game' => SteamPresenter::gameDetails($game),
            'achievements' => $achievements->map(fn (SteamAchievement $achievement) => SteamPresenter::achievement($achievement))->values(),
            'achievements_error' => $error,
            'months' => $stats->months($account, 12, $game),
            'store' => $store->for($game->app_id),
        ]);
    }
}
