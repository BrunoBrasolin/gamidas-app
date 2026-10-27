<?php

namespace App\Http\Presenters;

use App\Models\WatchedItem;
use App\Models\WatchProgress;

/**
 * Shapes watch history records for the frontend (resources/js/types/watched.ts).
 */
class WatchPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function item(WatchedItem $item): array
    {
        return [
            ...self::media($item),
            'id' => $item->id,
            'viewer' => $item->viewer,
            'watched_at' => $item->watched_at->toIso8601String(),
            'watched_on' => $item->watched_on->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function progress(WatchProgress $progress): array
    {
        return [
            ...self::media($progress),
            'id' => $progress->id,
            'viewer' => $progress->viewer === '' ? null : $progress->viewer,
            'position_seconds' => $progress->position_seconds,
            'playing' => $progress->isPlaying(),
            'last_played_at' => $progress->last_played_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function media(WatchedItem|WatchProgress $entry): array
    {
        return [
            'type' => $entry->type->value,
            'title' => $entry->title,
            'series_name' => $entry->series_name,
            'season_number' => $entry->season_number,
            'episode_number' => $entry->episode_number,
            'year' => $entry->year,
            'runtime_seconds' => $entry->runtime_seconds,
        ];
    }
}
