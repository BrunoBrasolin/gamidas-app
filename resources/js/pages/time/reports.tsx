import { Head, Link } from '@inertiajs/react';
import { BarChart3 } from 'lucide-react';
import { EmptyState } from '@/components/finance/empty-state';
import { FilterSelect } from '@/components/finance/filter-select';
import { PageHeader } from '@/components/finance/page-header';
import { StatCard } from '@/components/finance/stat-card';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useQueryFilters } from '@/hooks/use-query-filters';
import {
    formatDate,
    formatPercent,
    formatShortDate,
    today,
} from '@/lib/format';
import { formatDuration, shiftDay, weekday, weekdayIndex } from '@/lib/time';
import { cn } from '@/lib/utils';
import time from '@/routes/time';
import type { CategoryOption } from '@/types';

type Props = {
    filters: { from: string; to: string; category: number | null };
    fixed: {
        today_seconds: number;
        week_seconds: number;
        month_seconds: number;
    };
    period: {
        days: number;
        days_with_time: number;
        total_seconds: number;
        average_seconds: number;
        average_active_seconds: number;
        previous: { from: string; to: string; total_seconds: number };
    };
    by_day: { date: string; seconds: number }[];
    by_category: {
        category_id: number;
        name: string;
        archived: boolean;
        seconds: number;
        share: number;
    }[];
    heatmap: { from: string; to: string; days: Record<string, number> };
    category_options: CategoryOption[];
};

function ProgressBar({ percent, label }: { percent: number; label: string }) {
    return (
        <div
            role="progressbar"
            aria-label={label}
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={Math.round(percent)}
            className="h-2 overflow-hidden rounded-full bg-muted"
        >
            <div
                className="h-full rounded-full bg-primary"
                style={{ width: `${Math.max(0, Math.min(100, percent))}%` }}
            />
        </div>
    );
}

function presets(): [string, string, string][] {
    const now = today();
    const monday = shiftDay(now, -((weekdayIndex(now) + 6) % 7));

    return [
        ['Esta semana', monday, now],
        ['Este mês', `${now.slice(0, 8)}01`, now],
        ['Últimos 30 dias', shiftDay(now, -29), now],
        ['Este ano', `${now.slice(0, 4)}-01-01`, now],
    ];
}

export default function TimeReports({
    filters,
    fixed,
    period,
    by_day,
    by_category,
    heatmap,
    category_options,
}: Props) {
    const filter = useQueryFilters(time.reports.index.url(), filters);
    const max = Math.max(3600, ...by_day.map((day) => day.seconds));
    const difference = period.total_seconds - period.previous.total_seconds;

    return (
        <>
            <Head title="Relatórios de tempo" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Relatórios de tempo"
                    description="Somam as horas lançadas na planilha. Medem o tempo registrado, não a produtividade."
                />

                <div className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-1.5">
                        <Label htmlFor="report-from">De</Label>
                        <Input
                            id="report-from"
                            type="date"
                            value={filters.from}
                            max={filters.to}
                            onChange={(event) =>
                                event.target.value &&
                                filter({ from: event.target.value })
                            }
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="report-to">Até</Label>
                        <Input
                            id="report-to"
                            type="date"
                            value={filters.to}
                            min={filters.from}
                            onChange={(event) =>
                                event.target.value &&
                                filter({ to: event.target.value })
                            }
                        />
                    </div>
                    <FilterSelect
                        id="report-category"
                        label="Categoria"
                        value={filters.category ?? ''}
                        options={[
                            ['', 'Todas'],
                            ...category_options.map(
                                (category): [string, string] => [
                                    String(category.id),
                                    category.name,
                                ],
                            ),
                        ]}
                        onChange={(value) =>
                            filter({
                                category: value === '' ? null : Number(value),
                            })
                        }
                    />
                    <div className="flex flex-wrap gap-1">
                        {presets().map(([label, from, to]) => (
                            <Button
                                key={label}
                                size="sm"
                                variant={
                                    filters.from === from && filters.to === to
                                        ? 'secondary'
                                        : 'ghost'
                                }
                                onClick={() => filter({ from, to })}
                            >
                                {label}
                            </Button>
                        ))}
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <StatCard
                        title="Hoje"
                        value={formatDuration(fixed.today_seconds)}
                    />
                    <StatCard
                        title="Nesta semana"
                        value={formatDuration(fixed.week_seconds)}
                    />
                    <StatCard
                        title="Neste mês"
                        value={formatDuration(fixed.month_seconds)}
                    />
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <StatCard
                        title={`Total de ${formatShortDate(filters.from)} a ${formatShortDate(filters.to)}`}
                        value={formatDuration(period.total_seconds)}
                        hint={
                            <>
                                Período anterior (
                                {formatShortDate(period.previous.from)}–
                                {formatShortDate(period.previous.to)}):{' '}
                                {formatDuration(period.previous.total_seconds)}
                                {difference !== 0 &&
                                    ` (${difference > 0 ? '+' : '−'}${formatDuration(Math.abs(difference))})`}
                            </>
                        }
                    />
                    <StatCard
                        title="Média por dia"
                        value={formatDuration(period.average_seconds)}
                        hint={`Em ${period.days} dia(s) do período.`}
                    />
                    <StatCard
                        title="Média nos dias com registro"
                        value={formatDuration(period.average_active_seconds)}
                        hint={`${period.days_with_time} de ${period.days} dia(s) com tempo lançado.`}
                    />
                </div>

                {period.total_seconds === 0 ? (
                    <EmptyState
                        icon={BarChart3}
                        title="Nenhum tempo lançado no período"
                        description="Lance horas na planilha ou escolha outro período."
                    >
                        <Button asChild>
                            <Link href={time.index()}>Abrir a planilha</Link>
                        </Button>
                    </EmptyState>
                ) : (
                    <div className="grid gap-4 xl:grid-cols-2">
                        <Card>
                            <CardHeader>
                                <CardTitle>Tempo por dia</CardTitle>
                            </CardHeader>
                            <CardContent className="overflow-x-auto">
                                <ul
                                    className="flex h-40 items-end gap-0.5"
                                    style={{
                                        minWidth: `${by_day.length * 10}px`,
                                    }}
                                >
                                    {by_day.map((day) => (
                                        <li
                                            key={day.date}
                                            className="h-full flex-1"
                                        >
                                            <Link
                                                href={time.index({
                                                    query: {
                                                        view: 'week',
                                                        date: day.date,
                                                    },
                                                })}
                                                className="flex h-full items-end rounded-sm hover:bg-muted"
                                                title={`${formatDate(day.date)}: ${formatDuration(day.seconds)}`}
                                                aria-label={`${weekday(day.date)}, ${formatDate(day.date)}: ${formatDuration(day.seconds)}`}
                                            >
                                                <span
                                                    className="w-full rounded-sm bg-primary/80"
                                                    style={{
                                                        height: `${(day.seconds / max) * 100}%`,
                                                    }}
                                                />
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                                <div className="mt-1 flex justify-between text-xs text-muted-foreground">
                                    <span>{formatShortDate(filters.from)}</span>
                                    <span>{formatShortDate(filters.to)}</span>
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Tempo por categoria</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <ul className="space-y-3 text-sm">
                                    {by_category.map((row) => (
                                        <li
                                            key={row.category_id}
                                            className="space-y-1"
                                        >
                                            <div className="flex justify-between gap-2">
                                                <span className="truncate">
                                                    {row.name}
                                                    {row.archived && (
                                                        <span className="ml-1 text-xs text-muted-foreground">
                                                            (arquivada)
                                                        </span>
                                                    )}
                                                </span>
                                                <span className="shrink-0 tabular-nums">
                                                    {formatDuration(
                                                        row.seconds,
                                                    )}{' '}
                                                    · {formatPercent(row.share)}
                                                </span>
                                            </div>
                                            <ProgressBar
                                                percent={row.share / 100}
                                                label={`Parcela de ${row.name}`}
                                            />
                                        </li>
                                    ))}
                                </ul>
                            </CardContent>
                        </Card>
                    </div>
                )}

                <Heatmap heatmap={heatmap} />
            </div>
        </>
    );
}

const LEVELS = [
    'bg-muted',
    'bg-primary/25',
    'bg-primary/45',
    'bg-primary/70',
    'bg-primary',
];

function level(seconds: number): number {
    if (seconds <= 0) {
        return 0;
    }

    return seconds < 3600 ? 1 : seconds < 10800 ? 2 : seconds < 18000 ? 3 : 4;
}

/**
 * Days with recorded time over the last year. Lighter days simply have
 * less time recorded; they are not marked as failures.
 */
function Heatmap({ heatmap }: { heatmap: Props['heatmap'] }) {
    const weeks: string[][] = [];

    for (let day = heatmap.from; day <= heatmap.to; day = shiftDay(day, 1)) {
        if (weekdayIndex(day) === 1 || weeks.length === 0) {
            weeks.push([]);
        }

        weeks[weeks.length - 1].push(day);
    }

    const now = today();
    const recorded = Object.keys(heatmap.days).length;

    return (
        <Card>
            <CardHeader>
                <CardTitle>Dias com horas registradas</CardTitle>
                <CardDescription>
                    {recorded} dia(s) com registro nas últimas 53 semanas.
                </CardDescription>
            </CardHeader>
            <CardContent className="overflow-x-auto">
                <div
                    className="flex gap-0.5"
                    role="img"
                    aria-label={`${recorded} dias com tempo registrado nas últimas 53 semanas`}
                >
                    {weeks.map((week) => (
                        <div key={week[0]} className="flex flex-col gap-0.5">
                            {week.map((day) => {
                                const seconds = heatmap.days[day] ?? 0;

                                return (
                                    <Link
                                        key={day}
                                        href={time.index({
                                            query: { view: 'week', date: day },
                                        })}
                                        title={`${formatDate(day)}: ${seconds > 0 ? formatDuration(seconds) : 'sem registro'}`}
                                        tabIndex={-1}
                                        className={cn(
                                            'size-3 rounded-[2px]',
                                            day > now
                                                ? 'opacity-30'
                                                : LEVELS[level(seconds)],
                                            day > now && 'bg-muted',
                                        )}
                                    />
                                );
                            })}
                        </div>
                    ))}
                </div>
                <div className="mt-2 flex items-center gap-1 text-xs text-muted-foreground">
                    Menos
                    {LEVELS.map((color) => (
                        <span
                            key={color}
                            className={cn('size-3 rounded-[2px]', color)}
                        />
                    ))}
                    Mais
                </div>
            </CardContent>
        </Card>
    );
}

TimeReports.layout = {
    breadcrumbs: [
        { title: 'Tempo', href: time.index() },
        { title: 'Relatórios', href: time.reports.index() },
    ],
};
