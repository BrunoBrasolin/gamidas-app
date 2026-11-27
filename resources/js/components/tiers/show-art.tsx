import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { TierShow } from '@/types';

const COLORS = [
    'from-rose-600 to-rose-950',
    'from-orange-600 to-orange-950',
    'from-amber-600 to-amber-950',
    'from-emerald-600 to-emerald-950',
    'from-teal-600 to-teal-950',
    'from-sky-600 to-sky-950',
    'from-indigo-600 to-indigo-950',
    'from-violet-600 to-violet-950',
    'from-fuchsia-600 to-fuchsia-950',
    'from-slate-600 to-slate-950',
];

/** The same color for every show of a band. */
function colorOf(band: string): string {
    let hash = 0;

    for (const char of band.trim().toLowerCase()) {
        hash = (hash * 31 + (char.codePointAt(0) ?? 0)) >>> 0;
    }

    return COLORS[hash % COLORS.length];
}

/**
 * A show's tile on a tier list, drawn like a ticket since shows have no
 * cover: the band, the venue and the date.
 */
export function ShowArt({
    show,
    className,
}: {
    show: TierShow;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'flex aspect-square w-full flex-col justify-between gap-1 overflow-hidden rounded-md bg-gradient-to-br p-2 text-left text-white',
                colorOf(show.band),
                className,
            )}
        >
            <span className="line-clamp-3 text-xs leading-tight font-bold break-words sm:text-sm">
                {show.band}
            </span>
            <span className="block text-[10px] leading-tight text-white/80 sm:text-[11px]">
                <span className="line-clamp-2">{show.venue}</span>
                <span className="block tabular-nums">
                    {formatDate(show.date)}
                </span>
            </span>
        </span>
    );
}

/** Everything the tile may cut off, for its tooltip and screen readers. */
export function showDescription(show: TierShow): string {
    return [show.band, show.tour, show.venue, formatDate(show.date)]
        .filter(Boolean)
        .join(' · ');
}
