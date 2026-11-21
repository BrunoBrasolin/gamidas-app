import { useEffect, useRef, useState } from 'react';
import { formatDate, formatMoney, formatShortDate } from '@/lib/format';

export type BalancePoint = { date: string; balance_cents: number };

const HEIGHT = 220;
const PAD = { top: 16, right: 16, bottom: 28, left: 72 };

const compact = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
    notation: 'compact',
    maximumFractionDigits: 1,
});

/** Round tick values (in reais) covering min..max, about four of them. */
function ticks(min: number, max: number): number[] {
    const range = max - min || Math.max(Math.abs(max) * 0.02, 100);
    const raw = range / 4;
    const magnitude = 10 ** Math.floor(Math.log10(raw));
    const normalized = raw / magnitude;
    const step =
        (normalized <= 1 ? 1 : normalized <= 2 ? 2 : normalized <= 5 ? 5 : 10) *
        magnitude;
    const start = Math.floor((max - min ? min : min - range / 2) / step) * step;
    const end = Math.ceil((max - min ? max : max + range / 2) / step) * step;
    const values: number[] = [];

    for (let value = start; value <= end + step / 2; value += step) {
        values.push(value);
    }

    return values;
}

/**
 * The total invested balance over time, one point per day a sync recorded:
 * a 2px line on a non-zero baseline (so the growth shows), the last point
 * marked, a crosshair and tooltip on hover or arrow keys.
 */
export function BalanceChart({ points }: { points: BalancePoint[] }) {
    const ref = useRef<HTMLDivElement>(null);
    const [width, setWidth] = useState(0);
    const [active, setActive] = useState<number | null>(null);

    useEffect(() => {
        const element = ref.current;

        if (!element) {
            return;
        }

        const observer = new ResizeObserver(([entry]) =>
            setWidth(entry.contentRect.width),
        );
        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    const values = points.map((point) => point.balance_cents / 100);
    const yTicks = ticks(Math.min(...values), Math.max(...values));
    const yMin = yTicks[0];
    const yMax = yTicks[yTicks.length - 1];
    const plotWidth = Math.max(width - PAD.left - PAD.right, 1);
    const plotHeight = HEIGHT - PAD.top - PAD.bottom;
    const x = (index: number) =>
        PAD.left +
        (points.length === 1
            ? plotWidth
            : (index / (points.length - 1)) * plotWidth);
    const y = (value: number) =>
        PAD.top +
        plotHeight -
        ((value - yMin) / (yMax - yMin || 1)) * plotHeight;

    const path = points
        .map(
            (point, index) =>
                `${index === 0 ? 'M' : 'L'}${x(index)},${y(point.balance_cents / 100)}`,
        )
        .join(' ');
    const last = points.length - 1;
    const shown = active ?? last;
    const first = points[0];
    const latest = points[last];

    function pick(clientX: number) {
        const box = ref.current?.getBoundingClientRect();

        if (!box || points.length === 0) {
            return;
        }

        const ratio = (clientX - box.left - PAD.left) / plotWidth;
        setActive(
            Math.min(
                last,
                Math.max(0, Math.round(ratio * (points.length - 1))),
            ),
        );
    }

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                <span className="text-muted-foreground">
                    {formatDate(points[shown].date)}
                </span>
                <span className="font-semibold tabular-nums">
                    {formatMoney(points[shown].balance_cents)}
                </span>
            </div>
            <div
                ref={ref}
                className="relative w-full touch-pan-y outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                tabIndex={0}
                role="img"
                aria-label={`Patrimônio investido: ${formatMoney(first.balance_cents)} em ${formatDate(first.date)} e ${formatMoney(latest.balance_cents)} em ${formatDate(latest.date)}. Use as setas para ver cada dia.`}
                onPointerMove={(event) => pick(event.clientX)}
                onPointerDown={(event) => pick(event.clientX)}
                onPointerLeave={() => setActive(null)}
                onBlur={() => setActive(null)}
                onKeyDown={(event) => {
                    if (
                        event.key === 'ArrowLeft' ||
                        event.key === 'ArrowRight'
                    ) {
                        event.preventDefault();
                        const delta = event.key === 'ArrowLeft' ? -1 : 1;
                        setActive((current) =>
                            Math.min(
                                last,
                                Math.max(0, (current ?? last) + delta),
                            ),
                        );
                    }
                }}
            >
                {width > 0 && (
                    <svg width={width} height={HEIGHT} className="block">
                        {yTicks.map((tick) => (
                            <g key={tick}>
                                <line
                                    x1={PAD.left}
                                    x2={width - PAD.right}
                                    y1={y(tick)}
                                    y2={y(tick)}
                                    className="stroke-border"
                                    strokeWidth={1}
                                />
                                <text
                                    x={PAD.left - 8}
                                    y={y(tick)}
                                    textAnchor="end"
                                    dominantBaseline="middle"
                                    className="fill-muted-foreground text-[11px] tabular-nums"
                                >
                                    {compact.format(tick)}
                                </text>
                            </g>
                        ))}
                        <text
                            x={PAD.left}
                            y={HEIGHT - 8}
                            className="fill-muted-foreground text-[11px]"
                        >
                            {formatShortDate(first.date)}
                        </text>
                        {points.length > 1 && (
                            <text
                                x={width - PAD.right}
                                y={HEIGHT - 8}
                                textAnchor="end"
                                className="fill-muted-foreground text-[11px]"
                            >
                                {formatShortDate(latest.date)}
                            </text>
                        )}
                        {active !== null && (
                            <line
                                x1={x(active)}
                                x2={x(active)}
                                y1={PAD.top}
                                y2={PAD.top + plotHeight}
                                className="stroke-muted-foreground/50"
                                strokeWidth={1}
                            />
                        )}
                        <path
                            d={path}
                            fill="none"
                            stroke="var(--chart-2)"
                            strokeWidth={2}
                            strokeLinejoin="round"
                            strokeLinecap="round"
                        />
                        <circle
                            cx={x(shown)}
                            cy={y(points[shown].balance_cents / 100)}
                            r={4}
                            fill="var(--chart-2)"
                            className="stroke-card"
                            strokeWidth={2}
                        />
                    </svg>
                )}
            </div>
        </div>
    );
}
