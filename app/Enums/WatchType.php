<?php

namespace App\Enums;

/**
 * Kinds of items recorded in the watch history. Jellyfin item types not
 * listed here (music, audiobooks, photos…) are not recorded.
 */
enum WatchType: string
{
    case Movie = 'movie';
    case Episode = 'episode';
    case Video = 'video';

    public static function fromJellyfin(string $itemType): ?self
    {
        return match ($itemType) {
            'Movie' => self::Movie,
            'Episode' => self::Episode,
            'Video', 'MusicVideo' => self::Video,
            default => null,
        };
    }
}
