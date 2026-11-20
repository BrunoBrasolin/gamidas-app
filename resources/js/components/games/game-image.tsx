import { Gamepad2 } from 'lucide-react';
import { useState } from 'react';
import { cn } from '@/lib/utils';

/**
 * A game image from Steam's CDN that falls back through the given URLs (not
 * every game has a library cover, and old ones may lack a header) and, when
 * none loads, to the game's name on a plain tile.
 */
export function GameImage({
    urls,
    name,
    className,
}: {
    urls: (string | null)[];
    name: string;
    className?: string;
}) {
    const sources = urls.filter((url): url is string => url !== null);
    const [index, setIndex] = useState(0);

    if (index >= sources.length) {
        return (
            <div
                className={cn(
                    'flex flex-col items-center justify-center gap-2 bg-muted p-3 text-center text-muted-foreground',
                    className,
                )}
            >
                <Gamepad2 className="size-6 shrink-0" aria-hidden />
                <span className="line-clamp-3 text-xs font-medium">{name}</span>
            </div>
        );
    }

    return (
        <img
            src={sources[index]}
            alt=""
            loading="lazy"
            onError={() => setIndex(index + 1)}
            className={cn('bg-muted object-cover', className)}
        />
    );
}
