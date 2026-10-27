<?php

namespace App\Services;

use App\Enums\WatchType;
use App\Models\JellyfinConnection;
use App\Models\User;
use App\Models\WatchedItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The watch history: the Jellyfin connection of each user and the items
 * recorded by its webhook. Everything belongs to a single user.
 */
class WatchHistoryService
{
    public const RECORDED = 'recorded';

    public const DUPLICATE = 'duplicate';

    public const IGNORED = 'ignored';

    /**
     * Create or replace the user's webhook token. The token is returned once;
     * only its hash is kept, so the previous one stops working.
     */
    public function issueToken(User $user): string
    {
        $token = Str::random(48);

        JellyfinConnection::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['token_hash' => JellyfinConnection::hashToken($token), 'last_received_at' => null],
        );

        return $token;
    }

    public function revokeToken(User $user): void
    {
        JellyfinConnection::query()->where('user_id', $user->id)->delete();
    }

    public function connectionForToken(string $token): ?JellyfinConnection
    {
        if ($token === '') {
            return null;
        }

        return JellyfinConnection::query()->with('user')->firstWhere('token_hash', JellyfinConnection::hashToken($token));
    }

    /**
     * Record a webhook event. Only playbacks stopped after the end (Jellyfin's
     * PlayedToCompletion) of a movie, episode or video are kept; an item is
     * recorded once per local day.
     *
     * @param  array<array-key, mixed>  $data
     * @return self::RECORDED|self::DUPLICATE|self::IGNORED
     *
     * @throws InvalidArgumentException when the payload is not a Jellyfin event
     */
    public function record(JellyfinConnection $connection, array $data): string
    {
        $payload = new JellyfinPayload($data);
        $event = $payload->string('NotificationType', 64);

        if ($event === null) {
            throw new InvalidArgumentException('O evento não tem NotificationType. Ative "Send All Properties" no webhook do Jellyfin.');
        }

        $connection->forceFill(['last_received_at' => Date::now()])->save();

        $type = WatchType::fromJellyfin($payload->string('ItemType', 32) ?? '');

        if ($event !== 'PlaybackStop' || ! $payload->bool('PlayedToCompletion') || $type === null) {
            return self::IGNORED;
        }

        $itemId = $payload->string('ItemId', 64);
        $title = $payload->string('Name');

        if ($itemId === null || $title === null) {
            throw new InvalidArgumentException('O evento não tem ItemId ou Name.');
        }

        $watchedAt = $payload->stoppedAt();
        $episode = $type === WatchType::Episode;

        $item = WatchedItem::query()->createOrFirst(
            [
                'user_id' => $connection->user_id,
                'jellyfin_item_id' => $itemId,
                'watched_on' => $watchedAt->toDateString(),
            ],
            [
                'type' => $type,
                'title' => $title,
                'series_name' => $episode ? $payload->string('SeriesName') : null,
                'season_number' => $episode ? $payload->int('SeasonNumber') : null,
                'episode_number' => $episode ? $payload->int('EpisodeNumber') : null,
                'year' => $payload->int('Year', 9_999),
                'runtime_seconds' => $payload->runtimeSeconds(),
                'watched_at' => $watchedAt,
            ],
        );

        return $item->wasRecentlyCreated ? self::RECORDED : self::DUPLICATE;
    }

    public function delete(WatchedItem $item): void
    {
        $item->delete();
    }

    /**
     * Years that can be browsed, newest first: from the oldest entry up to
     * the current year (or a later entry's year).
     *
     * @return list<int>
     */
    public function years(User $user): array
    {
        $current = Date::today()->year;
        $oldest = $user->watchedItems()->min('watched_on');
        $newest = $user->watchedItems()->max('watched_on');

        $from = $oldest === null ? $current : min($current, (int) substr((string) $oldest, 0, 4));
        $to = $newest === null ? $current : max($current, (int) substr((string) $newest, 0, 4));

        return range($to, $from);
    }

    /**
     * Entries of one year, newest first.
     *
     * @return Collection<int, WatchedItem>
     */
    public function itemsOfYear(User $user, int $year): Collection
    {
        return $user->watchedItems()
            ->whereBetween('watched_on', [sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year)])
            ->orderByDesc('watched_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Totals over some entries. Series count once each, ignoring case and
     * accents; time sums the known runtimes.
     *
     * @param  Collection<int, WatchedItem>  $items
     * @return array{items: int, movies: int, episodes: int, series: int, seconds: int}
     */
    public function stats(Collection $items): array
    {
        $episodes = $items->where('type', WatchType::Episode);

        return [
            'items' => $items->count(),
            'movies' => $items->where('type', WatchType::Movie)->count(),
            'episodes' => $episodes->count(),
            'series' => $episodes->pluck('series_name')->filter()
                ->map(fn (string $name) => Str::lower(Str::ascii(trim($name))))
                ->unique()->count(),
            'seconds' => (int) $items->sum('runtime_seconds'),
        ];
    }
}
