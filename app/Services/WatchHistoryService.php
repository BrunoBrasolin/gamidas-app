<?php

namespace App\Services;

use App\Enums\WatchType;
use App\Models\Household;
use App\Models\JellyfinConnection;
use App\Models\WatchedItem;
use App\Models\WatchProgress;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The watch history: the household's Jellyfin connection and the items
 * recorded by its webhook, shared by every member of the household.
 */
class WatchHistoryService
{
    public const RECORDED = 'recorded';

    public const DUPLICATE = 'duplicate';

    public const TRACKED = 'tracked';

    public const IGNORED = 'ignored';

    /**
     * Stops before this many seconds do not count as started.
     */
    public const MIN_PROGRESS_SECONDS = 60;

    /**
     * Create or replace the household's webhook token. The token is returned
     * once; only its hash is kept, so the previous one stops working.
     */
    public function issueToken(Household $household): string
    {
        $token = Str::random(48);

        JellyfinConnection::query()->updateOrCreate(
            ['household_id' => $household->id],
            ['token_hash' => JellyfinConnection::hashToken($token), 'last_received_at' => null],
        );

        return $token;
    }

    public function revokeToken(Household $household): void
    {
        JellyfinConnection::query()->where('household_id', $household->id)->delete();
    }

    public function connectionForToken(string $token): ?JellyfinConnection
    {
        if ($token === '') {
            return null;
        }

        return JellyfinConnection::query()->firstWhere('token_hash', JellyfinConnection::hashToken($token));
    }

    /**
     * Handle a webhook event of a movie, episode or video. Playback Start
     * marks it as playing for that person; Playback Stop before the end
     * (Jellyfin's PlayedToCompletion) keeps where they stopped, and after the
     * end records it in the history, with the Jellyfin user who watched it,
     * and forgets where they stopped. An item is recorded once per local day
     * for the household, whoever watched it.
     *
     * @param  array<array-key, mixed>  $data
     * @return self::RECORDED|self::DUPLICATE|self::TRACKED|self::IGNORED
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

        if ($type === null || ! in_array($event, ['PlaybackStart', 'PlaybackStop'], true)) {
            return self::IGNORED;
        }

        $itemId = $payload->string('ItemId', 64);
        $title = $payload->string('Name');

        if ($itemId === null || $title === null) {
            throw new InvalidArgumentException('O evento não tem ItemId ou Name.');
        }

        $episode = $type === WatchType::Episode;
        $media = [
            'type' => $type,
            'title' => $title,
            'series_name' => $episode ? $payload->string('SeriesName') : null,
            'season_number' => $episode ? $payload->int('SeasonNumber') : null,
            'episode_number' => $episode ? $payload->int('EpisodeNumber') : null,
            'year' => $payload->int('Year', 9_999),
            'runtime_seconds' => $payload->runtimeSeconds(),
            'provider_ids' => $payload->providerIds() ?: null,
        ];
        $progress = [
            'household_id' => $connection->household_id,
            'viewer' => $payload->string('NotificationUsername', 100) ?? '',
            'jellyfin_item_id' => $itemId,
        ];

        if ($event === 'PlaybackStart') {
            WatchProgress::query()->updateOrCreate($progress, [
                ...$media,
                'position_seconds' => $payload->positionSeconds(),
                'playing_since' => Date::now(),
                'last_played_at' => Date::now(),
            ]);

            return self::TRACKED;
        }

        if ($payload->bool('PlayedToCompletion')) {
            WatchProgress::query()->where($progress)->delete();

            return $this->recordWatched($connection, $itemId, $payload, $media);
        }

        $position = $payload->positionSeconds();

        if ($position < self::MIN_PROGRESS_SECONDS) {
            // Barely started: forget it, unless an earlier session got further.
            WatchProgress::query()->where($progress)->where('position_seconds', '<', self::MIN_PROGRESS_SECONDS)->delete();
            WatchProgress::query()->where($progress)->update(['playing_since' => null]);

            return self::IGNORED;
        }

        WatchProgress::query()->updateOrCreate($progress, [
            ...$media,
            'position_seconds' => $position,
            'playing_since' => null,
            'last_played_at' => $payload->stoppedAt(),
        ]);

        return self::TRACKED;
    }

    /**
     * @param  array<string, mixed>  $media
     * @return self::RECORDED|self::DUPLICATE
     */
    private function recordWatched(JellyfinConnection $connection, string $itemId, JellyfinPayload $payload, array $media): string
    {
        $watchedAt = $payload->stoppedAt();

        $item = WatchedItem::query()->createOrFirst(
            [
                'household_id' => $connection->household_id,
                'jellyfin_item_id' => $itemId,
                'watched_on' => $watchedAt->toDateString(),
            ],
            [
                ...$media,
                'viewer' => $payload->string('NotificationUsername', 100),
                'watched_at' => $watchedAt,
            ],
        );

        return $item->wasRecentlyCreated ? self::RECORDED : self::DUPLICATE;
    }

    public function delete(WatchedItem $item): void
    {
        $item->delete();
    }

    public function deleteProgress(WatchProgress $progress): void
    {
        $progress->delete();
    }

    /**
     * Items someone of the household started and has not finished, the most
     * recently played first.
     *
     * @return Collection<int, WatchProgress>
     */
    public function inProgress(Household $household, int $limit = 12): Collection
    {
        return $household->watchProgress()
            ->orderByDesc('last_played_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The last episode watched of each series in the recent months, the most
     * recently watched series first. Series match ignoring case and accents.
     *
     * @return Collection<int, WatchedItem>
     */
    public function seriesInProgress(Household $household, int $months = 6, int $limit = 12): Collection
    {
        return $household->watchedItems()
            ->where('type', WatchType::Episode)
            ->whereNotNull('series_name')
            ->where('watched_at', '>=', Date::now()->subMonthsNoOverflow($months))
            ->orderByDesc('watched_at')
            ->orderByDesc('id')
            ->get()
            ->unique(fn (WatchedItem $item) => Str::lower(Str::ascii(trim((string) $item->series_name))))
            ->take($limit)
            ->values();
    }

    /**
     * Years that can be browsed, newest first: from the oldest entry up to
     * the current year (or a later entry's year).
     *
     * @return list<int>
     */
    public function years(Household $household): array
    {
        $current = Date::today()->year;
        $oldest = $household->watchedItems()->min('watched_on');
        $newest = $household->watchedItems()->max('watched_on');

        $from = $oldest === null ? $current : min($current, (int) substr((string) $oldest, 0, 4));
        $to = $newest === null ? $current : max($current, (int) substr((string) $newest, 0, 4));

        return range($to, $from);
    }

    /**
     * Entries of one year, newest first.
     *
     * @return Collection<int, WatchedItem>
     */
    public function itemsOfYear(Household $household, int $year): Collection
    {
        return $household->watchedItems()
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
