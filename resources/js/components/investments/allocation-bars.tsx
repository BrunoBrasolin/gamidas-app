import { formatMoney, formatPercent } from '@/lib/format';

export type AllocationSlice = {
    type: string;
    label: string;
    balance_cents: number;
};

/**
 * How the balance splits by type: one row per type with a thin bar for its
 * share of the total (single hue: it is a magnitude, not an identity).
 */
export function AllocationBars({ slices }: { slices: AllocationSlice[] }) {
    const total = slices.reduce((sum, slice) => sum + slice.balance_cents, 0);

    return (
        <ul className="space-y-3">
            {slices.map((slice) => {
                const share =
                    total > 0
                        ? Math.round((slice.balance_cents * 10_000) / total)
                        : 0;

                return (
                    <li
                        key={slice.label}
                        className="space-y-1"
                        title={`${slice.label}: ${formatMoney(slice.balance_cents)} (${formatPercent(share)})`}
                    >
                        <div className="flex items-baseline justify-between gap-2 text-sm">
                            <span className="font-medium">{slice.label}</span>
                            <span className="text-muted-foreground tabular-nums">
                                {formatMoney(slice.balance_cents)} ·{' '}
                                {formatPercent(share)}
                            </span>
                        </div>
                        <div className="h-2 w-full rounded-r-[4px] bg-muted">
                            <div
                                className="h-full rounded-r-[4px]"
                                style={{
                                    width: `${share / 100}%`,
                                    background: 'var(--chart-2)',
                                }}
                            />
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}
