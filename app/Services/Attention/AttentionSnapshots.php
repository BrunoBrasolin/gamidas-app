<?php

namespace App\Services\Attention;

use App\Models\AttentionGame;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * What the public pages of the attention game show, kept in a file cache so
 * that polling phones and projectors don't each open a database connection
 * (the hosting plan limits those per hour). Only writes touch the database.
 *
 * @phpstan-type RoomInfo array{title: string, code: string, open: bool}
 * @phpstan-type Snapshot array{open: bool, players: int, finished: int, ranking: list<array<string, mixed>>, summary: array<string, int|float|null>, types: list<array<string, mixed>>}
 */
class AttentionSnapshots
{
    /**
     * Room info for the page a player opens, or null when there is no such room.
     *
     * @return RoomInfo|null
     */
    public function room(string $code): ?array
    {
        $code = $this->normalize($code);

        if ($code === null) {
            return null;
        }

        /** @var array{found: bool, room?: RoomInfo} $entry */
        $entry = $this->store()->remember($this->roomKey($code), (int) config('attention.room_ttl'), function () use ($code) {
            $game = AttentionGame::query()->where('code', $code)->first();

            return $game === null
                ? ['found' => false]
                : ['found' => true, 'room' => ['title' => $game->title, 'code' => $game->code, 'open' => $game->isOpen()]];
        });

        return $entry['found'] ? $entry['room'] ?? null : null;
    }

    /**
     * The room's numbers and ranking. Within the TTL it is served from the
     * cache; after it, everyone keeps getting the old copy while one request
     * rebuilds it, so a crowd polling at once costs one query set, not many.
     *
     * @return Snapshot|null
     */
    public function live(string $code): ?array
    {
        $code = $this->normalize($code);

        if ($code === null) {
            return null;
        }

        /** @var array{found: bool, snapshot?: Snapshot} $entry */
        $entry = $this->store()->flexible(
            $this->liveKey($code),
            [(int) config('attention.snapshot_ttl'), (int) config('attention.snapshot_stale_ttl')],
            fn () => $this->build($code),
        );

        return $entry['found'] ? $entry['snapshot'] ?? null : null;
    }

    /**
     * Drop what is cached for a room, so the next request sees it as it is now.
     */
    public function forget(AttentionGame|string $game): void
    {
        $code = $this->normalize($game instanceof AttentionGame ? $game->code : $game);

        if ($code === null) {
            return;
        }

        $this->store()->forget($this->roomKey($code));
        $this->store()->forget($this->liveKey($code));
    }

    /**
     * @return array{found: bool, snapshot?: Snapshot}
     */
    private function build(string $code): array
    {
        $game = AttentionGame::query()->where('code', $code)->first();

        if ($game === null) {
            return ['found' => false];
        }

        $report = new AttentionReport($game);
        $summary = $report->summary();

        return ['found' => true, 'snapshot' => [
            'open' => $game->isOpen(),
            'players' => (int) $summary['players'],
            'finished' => (int) $summary['finished'],
            'ranking' => $report->ranking(),
            'summary' => $summary,
            'types' => $report->types(),
        ]];
    }

    /**
     * Codes are typed by hand, so case and stray spaces don't matter. Anything
     * that can't be a code is rejected before it reaches the cache or database.
     */
    private function normalize(string $code): ?string
    {
        $code = strtoupper(trim($code));

        return preg_match('/^[A-Z0-9]{3,12}$/', $code) === 1 ? $code : null;
    }

    private function roomKey(string $code): string
    {
        return "attention:room:{$code}";
    }

    private function liveKey(string $code): string
    {
        return "attention:live:{$code}";
    }

    private function store(): Repository
    {
        $store = Cache::store(config('attention.cache_store'));
        assert($store instanceof Repository);

        return $store;
    }
}
