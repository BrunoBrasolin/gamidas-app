<?php

namespace App\Http\Controllers\TierLists;

use App\Enums\TierListKind;
use App\Http\Controllers\Controller;
use App\Models\Show;
use App\Models\TierListShare;
use App\Models\User;
use App\Services\ShowTierService;
use App\Services\Steam\SteamListsService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public, read-only page of a shared tier list, opened by anyone with
 * the link. It shows only the owner's first name and what identifies each
 * item: a game's name, or a show's band, tour, venue and date. Playtime,
 * setlists, photo albums and everything else stay private.
 */
class PublicTierListController extends Controller
{
    public function __invoke(string $token, SteamListsService $games, ShowTierService $shows): Response
    {
        $share = TierListShare::query()->with('user')->where('token', $token)->firstOrFail();
        $owner = $share->user->firstName();
        $tiers = match ($share->kind) {
            TierListKind::Games => $this->games($share->user, $games),
            TierListKind::Shows => $this->shows($share->user, $shows),
        };
        $count = array_sum(array_map('count', $tiers));
        $title = "Tier list de {$share->kind->subject()} de {$owner}";

        return Inertia::render('tier-list/public', [
            'kind' => $share->kind->value,
            'title' => $title,
            'tiers' => $tiers,
        ])->withViewData(['preview' => [
            // Read by the apps the link is sent to, which don't run scripts.
            'title' => $title,
            'description' => $count === 1
                ? '1 item classificado de S a F.'
                : "{$count} {$share->kind->subject()} classificados de S a F.",
            'url' => $share->url(),
            'image' => $share->kind === TierListKind::Games ? $this->cover($tiers) : null,
        ]]);
    }

    /**
     * @return array<string, list<array{app_id: int, name: string}>>
     */
    private function games(User $user, SteamListsService $lists): array
    {
        return array_map(
            fn (array $games) => array_map(fn (array $game) => ['app_id' => $game['app_id'], 'name' => $game['name']], $games),
            $lists->tiers($user),
        );
    }

    /**
     * @return array<string, list<array{band: string, tour: string|null, venue: string, date: string}>>
     */
    private function shows(User $user, ShowTierService $service): array
    {
        return array_map(
            fn (array $shows) => array_map(fn (Show $show) => [
                'band' => $show->band,
                'tour' => $show->tour,
                'venue' => $show->venue,
                'date' => $show->show_date->toDateString(),
            ], $shows),
            $service->tiers($user),
        );
    }

    /**
     * The store header of the best placed game.
     *
     * @param  array<string, list<array<string, mixed>>>  $tiers
     */
    private function cover(array $tiers): ?string
    {
        foreach ($tiers as $games) {
            if ($games !== []) {
                return "https://shared.cloudflare.steamstatic.com/store_item_assets/steam/apps/{$games[0]['app_id']}/header.jpg";
            }
        }

        return null;
    }
}
