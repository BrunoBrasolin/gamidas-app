import { useState } from 'react';
import { formatMonth, formatMoney, formatShortMonth } from '@/lib/format';
import { cn } from '@/lib/utils';

export type Contribution = { month: string; amount_cents: number };

/**
 * Money put into investments each month: columns up to 24px wide, rounded at
 * the top and square at the baseline. Hovering (or focusing) a month shows
 * its value above the chart; otherwise the period's total.
 */
export function ContributionsChart({ months }: { months: Contribution[] }) {
    const [active, setActive] = useState<number | null>(null);
    const max = Math.max(1, ...months.map((month) => month.amount_cents));
    const total = months.reduce((sum, month) => sum + month.amount_cents, 0);
    const current = active === null ? null : months[active];

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                <span className="text-muted-foreground">
                    {current
                        ? formatMonth(current.month)
                        : `Total em ${months.length} meses`}
                </span>
                <span className="font-semibold tabular-nums">
                    {formatMoney(current ? current.amount_cents : total)}
                </span>
            </div>
            <ul
                className="flex h-40 items-end gap-0.5 border-b border-border"
                onPointerLeave={() => setActive(null)}
            >
                {months.map((month, index) => (
                    <li
                        key={month.month}
                        className="flex h-full flex-1 justify-center"
                    >
                        <button
                            type="button"
                            className="flex h-full w-full max-w-10 items-end justify-center rounded-sm outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            aria-label={`${formatMonth(month.month)}: ${formatMoney(month.amount_cents)}`}
                            onPointerEnter={() => setActive(index)}
                            onFocus={() => setActive(index)}
                            onBlur={() => setActive(null)}
                        >
                            <span
                                className={cn(
                                    'w-full max-w-6 rounded-t-[4px] transition-opacity',
                                    active !== null &&
                                        active !== index &&
                                        'opacity-50',
                                )}
                                style={{
                                    height: `${(month.amount_cents / max) * 100}%`,
                                    minHeight: month.amount_cents > 0 ? 2 : 0,
                                    background: 'var(--chart-2)',
                                }}
                            />
                        </button>
                    </li>
                ))}
            </ul>
            {/* Every third month (ending on the current one) is labeled; the
                label may spill over its unlabeled neighbours. */}
            <ul
                className="flex gap-0.5 text-[11px] text-muted-foreground"
                aria-hidden
            >
                {months.map((month, index) => (
                    <li
                        key={month.month}
                        className="flex flex-1 justify-center whitespace-nowrap"
                    >
                        {(months.length - 1 - index) % 3 === 0 &&
                            formatShortMonth(month.month)}
                    </li>
                ))}
            </ul>
        </div>
    );
}
