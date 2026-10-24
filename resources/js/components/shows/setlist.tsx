import { cn } from '@/lib/utils';

/**
 * A numbered setlist, split into two columns on wide screens when long.
 */
export function Setlist({
    songs,
    className,
}: {
    songs: string[];
    className?: string;
}) {
    if (songs.length === 0) {
        return (
            <p className={cn('text-sm text-muted-foreground', className)}>
                Setlist não informado.
            </p>
        );
    }

    return (
        <ol
            className={cn(
                'list-decimal space-y-1 pl-8 text-sm marker:text-muted-foreground marker:tabular-nums',
                songs.length > 12 && 'md:columns-2 md:gap-10',
                className,
            )}
        >
            {songs.map((song, index) => (
                <li key={index} className="break-inside-avoid pl-1">
                    {song}
                </li>
            ))}
        </ol>
    );
}

/**
 * "Banda | Nome da tour", as the show is usually referred to.
 */
export function showTitle(show: { band: string; tour: string | null }) {
    return show.tour ? `${show.band} | ${show.tour}` : show.band;
}
