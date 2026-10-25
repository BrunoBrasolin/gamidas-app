import { Pin } from 'lucide-react';
import { KNOWLEDGE_COLORS } from '@/lib/knowledge';
import { cn } from '@/lib/utils';
import type { KnowledgeColor } from '@/types';

/**
 * A subject drawn as a book: the uploaded cover image or a cloth-like cover
 * in the accent color with the title set in serif, plus a shaded spine.
 */
export function BookCover({
    title,
    color,
    coverUrl,
    pinned = false,
    className,
    size = 'md',
}: {
    title: string;
    color: KnowledgeColor;
    coverUrl: string | null;
    pinned?: boolean;
    className?: string;
    size?: 'sm' | 'md';
}) {
    const palette = KNOWLEDGE_COLORS[color] ?? KNOWLEDGE_COLORS.sage;

    return (
        <div
            className={cn(
                'relative aspect-[3/4] w-full overflow-hidden rounded-l-sm rounded-r-md shadow-[0_1px_2px_rgba(0,0,0,0.12),0_8px_20px_-8px_rgba(60,40,20,0.45)]',
                className,
            )}
            style={{
                background: `linear-gradient(155deg, ${palette.light} 0%, ${palette.dark} 100%)`,
            }}
        >
            {coverUrl ? (
                <img
                    src={coverUrl}
                    alt=""
                    loading="lazy"
                    decoding="async"
                    className="absolute inset-0 size-full object-cover"
                />
            ) : (
                <div className="absolute inset-0 flex flex-col items-center justify-center px-[14%] text-center text-white/95">
                    <div
                        className={cn(
                            'absolute border border-white/25',
                            size === 'sm' ? 'inset-1.5' : 'inset-3',
                        )}
                        aria-hidden
                    />
                    <p
                        className={cn(
                            'line-clamp-5 font-serif leading-tight font-semibold break-words [text-shadow:0_1px_1px_rgba(0,0,0,0.25)]',
                            size === 'sm' ? 'text-xs' : 'text-lg',
                        )}
                    >
                        {title}
                    </p>
                    {size === 'md' && (
                        <span
                            className="mt-3 h-px w-8 bg-white/50"
                            aria-hidden
                        />
                    )}
                </div>
            )}
            {/* Spine: a darker band with a highlight, like a hardcover. */}
            <div
                className="pointer-events-none absolute inset-y-0 left-0 w-[7%] bg-gradient-to-r from-black/30 via-black/10 to-white/10"
                aria-hidden
            />
            {pinned && (
                <span className="absolute top-2 right-2 flex size-7 items-center justify-center rounded-full bg-white/90 text-neutral-800 shadow">
                    <Pin className="size-3.5" aria-hidden />
                    <span className="sr-only">Fixada</span>
                </span>
            )}
        </div>
    );
}
