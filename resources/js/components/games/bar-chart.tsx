import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';

export type Bar = {
    key: string;
    /** Under the bar; omitted bars still take their place. */
    label?: string;
    value: number;
    /** Tooltip and accessible name. */
    title: string;
};

/**
 * Vertical bars scaled to the largest value, with optional labels below.
 */
export function BarChart({
    bars,
    className,
}: {
    bars: Bar[];
    className?: string;
}) {
    const max = Math.max(1, ...bars.map((bar) => bar.value));

    return (
        <div className={cn('space-y-1', className)}>
            <ul className="flex h-36 items-end gap-1">
                {bars.map((bar) => (
                    <li
                        key={bar.key}
                        className="flex h-full flex-1 items-end"
                        title={bar.title}
                        aria-label={bar.title}
                    >
                        <span
                            className={cn(
                                'w-full rounded-sm',
                                bar.value > 0 ? 'bg-primary' : 'bg-muted',
                            )}
                            style={{
                                height:
                                    bar.value > 0
                                        ? `${Math.max(2, (bar.value / max) * 100)}%`
                                        : '2px',
                            }}
                        />
                    </li>
                ))}
            </ul>
            {/* Labels may be wider than their bar: centered under it, overflowing. */}
            <div className="flex h-4 gap-1" aria-hidden>
                {bars.map((bar) => (
                    <span key={bar.key} className="relative flex-1">
                        {bar.label && (
                            <span className="absolute left-1/2 -translate-x-1/2 text-[10px] whitespace-nowrap text-muted-foreground">
                                {bar.label}
                            </span>
                        )}
                    </span>
                ))}
            </div>
        </div>
    );
}

/**
 * A labeled horizontal bar relative to a maximum.
 */
export function MeterRow({
    label,
    value,
    max,
    display,
    href,
}: {
    label: string;
    value: number;
    max: number;
    display: string;
    href?: string;
}) {
    const percent = max > 0 ? Math.min(100, (value / max) * 100) : 0;

    return (
        <li className="space-y-1">
            <div className="flex items-baseline justify-between gap-3 text-sm">
                {href ? (
                    <Link href={href} className="truncate hover:underline">
                        {label}
                    </Link>
                ) : (
                    <span className="truncate">{label}</span>
                )}
                <span className="shrink-0 text-muted-foreground tabular-nums">
                    {display}
                </span>
            </div>
            <div className="h-2 overflow-hidden rounded-full bg-muted">
                <div
                    className="h-full rounded-full bg-primary"
                    style={{ width: `${percent}%` }}
                />
            </div>
        </li>
    );
}
