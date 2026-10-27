import type { ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

export type ShapeKind =
    | 'circle'
    | 'square'
    | 'triangle'
    | 'diamond'
    | 'star'
    | 'star-outline';

export const SHAPE_COLORS: Record<string, string> = {
    blue: '#3b82f6',
    red: '#ef4444',
    green: '#22c55e',
    yellow: '#eab308',
    purple: '#a855f7',
    white: '#f8fafc',
};

/**
 * Shapes are drawn as SVG so they look the same on every phone (Unicode
 * symbols turn into emoji on some systems).
 */
export function Shape({
    kind,
    color = SHAPE_COLORS.white,
    className,
}: {
    kind: ShapeKind;
    color?: string;
    className?: string;
}) {
    const star = '50,6 61,38 95,38 67,58 78,91 50,71 22,91 33,58 5,38 39,38';

    return (
        <svg
            viewBox="0 0 100 100"
            className={cn('size-16', className)}
            aria-hidden
        >
            {kind === 'circle' && (
                <circle cx="50" cy="50" r="42" fill={color} />
            )}
            {kind === 'square' && (
                <rect
                    x="10"
                    y="10"
                    width="80"
                    height="80"
                    rx="6"
                    fill={color}
                />
            )}
            {kind === 'triangle' && (
                <polygon points="50,8 94,90 6,90" fill={color} />
            )}
            {kind === 'diamond' && (
                <polygon points="50,4 94,50 50,96 6,50" fill={color} />
            )}
            {kind === 'star' && <polygon points={star} fill={color} />}
            {kind === 'star-outline' && (
                <polygon
                    points={star}
                    fill="none"
                    stroke={color}
                    strokeWidth="7"
                    strokeLinejoin="round"
                />
            )}
        </svg>
    );
}

/** A bar that empties over the given time. Remount it (key) to restart. */
export function TimerBar({
    durationMs,
    className,
}: {
    durationMs: number;
    className?: string;
}) {
    const [running, setRunning] = useState(false);

    useEffect(() => {
        const frame = requestAnimationFrame(() => setRunning(true));

        return () => cancelAnimationFrame(frame);
    }, []);

    return (
        <div
            className={cn(
                'h-2 w-full overflow-hidden rounded-full bg-white/15',
                className,
            )}
        >
            <div
                className="h-full rounded-full bg-gradient-to-r from-emerald-400 via-amber-400 to-rose-500"
                style={{
                    width: running ? '0%' : '100%',
                    transition: running
                        ? `width ${durationMs}ms linear`
                        : 'none',
                }}
            />
        </div>
    );
}

/**
 * Calls onExpire once after the given time, unless the component unmounts.
 * Returns a function telling how many ms have passed since it started.
 */
export function useDeadline(durationMs: number, onExpire: () => void) {
    const started = useRef(0);
    const expire = useRef(onExpire);

    useEffect(() => {
        expire.current = onExpire;
    });

    useEffect(() => {
        started.current = performance.now();
        const timer = window.setTimeout(() => expire.current(), durationMs);

        return () => window.clearTimeout(timer);
    }, [durationMs]);

    return () => Math.round(performance.now() - started.current);
}

/** Whole seconds left of a countdown, updated a few times a second. */
export function useSecondsLeft(durationMs: number): number {
    const [left, setLeft] = useState(Math.ceil(durationMs / 1000));

    useEffect(() => {
        const end = performance.now() + durationMs;
        const timer = window.setInterval(() => {
            setLeft(Math.max(0, Math.ceil((end - performance.now()) / 1000)));
        }, 200);

        return () => window.clearInterval(timer);
    }, [durationMs]);

    return left;
}

export function ChallengeFrame({
    title,
    hint,
    timer,
    children,
}: {
    title: ReactNode;
    hint?: ReactNode;
    timer?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="flex flex-1 flex-col gap-4">
            <div className="space-y-2 text-center">
                <p className="text-lg font-semibold">{title}</p>
                {hint && <p className="text-sm text-white/70">{hint}</p>}
                {timer}
            </div>
            {children}
        </div>
    );
}

export function OptionButton({
    children,
    selected,
    onClick,
    disabled,
    className,
}: {
    children: ReactNode;
    selected?: boolean;
    onClick: () => void;
    disabled?: boolean;
    className?: string;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            className={cn(
                'rounded-2xl border-2 px-4 py-4 text-2xl font-bold transition active:scale-95 disabled:opacity-60',
                selected
                    ? 'border-white bg-white text-slate-900'
                    : 'border-white/20 bg-white/10 hover:bg-white/20',
                className,
            )}
        >
            {children}
        </button>
    );
}

/** Percentage from 0 to 100 that drops a fixed amount per unit missed. */
export function closeness(
    expected: number,
    answer: number | null,
    step: number,
) {
    if (answer === null) {
        return 0;
    }

    return Math.max(0, 100 - step * Math.abs(expected - answer));
}

export function plural(count: number, one: string, many: string): string {
    return `${count} ${count === 1 ? one : many}`;
}
