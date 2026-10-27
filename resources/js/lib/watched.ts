import { Film, Tv, Video } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { WatchMedia, WatchType } from '@/types';

export const WATCH_TYPE_ICONS: Record<WatchType, LucideIcon> = {
    movie: Film,
    episode: Tv,
    video: Video,
};

/** "T01E03", or whichever part is known. */
export function episodeCode(media: WatchMedia): string | null {
    const pad = (value: number) => String(value).padStart(2, '0');
    const parts = [
        media.season_number !== null ? `T${pad(media.season_number)}` : '',
        media.episode_number !== null ? `E${pad(media.episode_number)}` : '',
    ].join('');

    return parts === '' ? null : parts;
}

/** "Série · T01E03 · Episódio" or "Filme (2014)". */
export function watchHeading(media: WatchMedia): string {
    if (media.type === 'episode' && media.series_name) {
        return [media.series_name, episodeCode(media), media.title]
            .filter(Boolean)
            .join(' · ');
    }

    return media.year ? `${media.title} (${media.year})` : media.title;
}

/** How far into the item, from 0 to 100, when its runtime is known. */
export function progressPercent(
    position: number,
    runtime: number | null,
): number | null {
    if (!runtime) {
        return null;
    }

    return Math.min(100, Math.max(0, Math.round((position / runtime) * 100)));
}
