import { Head, Link } from '@inertiajs/react';
import {
    BarChart3,
    CalendarX2,
    Flame,
    Target,
    TriangleAlert,
} from 'lucide-react';
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
    capitalize,
    formatDate,
    formatMonth,
    formatPercent,
    formatShortDate,
    today,
} from '@/lib/format';
import {
    formatDuration,
    shiftDay,
    shortWeekday,
    weekday,
    weekdayIndex,
} from '@/lib/time';
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
    goals: {
        has_goal: boolean;
        business_days: number;
        met: number;
        missed: number;
        pending: number;
        future: number;
        rate: number | null;
        shortfall_seconds: number;
        current_streak: number;
        best_streak: number;
        off_days_worked: number;
        off_seconds: number;
    };
    by_day: ReportDay[];
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

type DayStatus =
    | 'met'
    | 'missed'
    | 'pending'
    | 'future'
    | 'no_goal'
    | 'off'
    | 'off_worked';

type ReportDay = {
    date: string;
    seconds: number;
    goal_seconds: number | null;
    shortfall_seconds: number;
    business: boolean;
    holiday: string | null;
    status: DayStatus;
    categories: {
        category_id: number;
        name: string;
        seconds: number;
        goal_seconds: number | null;
    }[];
};

const STATUS: Record<DayStatus, { label: string; cell: string; bar: string }> =
    {
        met: {
            label: 'Meta atingida',
            cell: 'bg-green-500/20 text-green-800 dark:text-green-300',
            bar: 'bg-green-600/80',
        },
        missed: {
            label: 'Abaixo da meta',
            cell: 'bg-red-500/20 text-red-800 dark:text-red-300',
            bar: 'bg-red-600/80',
        },
        pending: {
            label: 'Hoje, em andamento',
            cell: 'bg-muted ring-2 ring-primary ring-inset',
            bar: 'bg-primary/80',
        },
        future: {
            label: 'Dia útil futuro',
            cell: 'border border-dashed text-muted-foreground',
            bar: 'bg-primary/80',
        },
        no_goal: {
            label: 'Dia útil sem meta',
            cell: 'bg-muted',
            bar: 'bg-primary/80',
        },
        off: {
            label: 'Fim de semana ou feriado',
            cell: 'text-muted-foreground/60',
            bar: 'bg-muted-foreground/40',
        },
        off_worked: {
            label: 'Trabalhou em dia não útil',
            cell: 'bg-amber-500/25 text-amber-800 dark:text-amber-300',
            bar: 'bg-amber-500/90',
        },
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
    goals,
    by_day,
    by_category,
    heatmap,
    category_options,
}: Props) {
    const filter = useQueryFilters(time.reports.index.url(), filters);
    const max = Math.max(
        3600,
        ...by_day.map((day) => Math.max(day.seconds, day.goal_seconds ?? 0)),
    );
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

                <GoalSection goals={goals} days={by_day} />

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
                                {goals.has_goal && (
                                    <CardDescription>
                                        O traço marca a meta de cada dia útil.
                                    </CardDescription>
                                )}
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
                                                className="relative flex h-full items-end rounded-sm hover:bg-muted"
                                                title={dayTitle(day)}
                                                aria-label={`${weekday(day.date)}, ${dayTitle(day)}`}
                                            >
                                                <span
                                                    className={cn(
                                                        'w-full rounded-sm',
                                                        STATUS[day.status].bar,
                                                    )}
                                                    style={{
                                                        height: `${(day.seconds / max) * 100}%`,
                                                    }}
                                                />
                                                {day.goal_seconds !== null && (
                                                    <span
                                                        aria-hidden
                                                        className="absolute inset-x-0 h-0.5 bg-foreground/60"
                                                        style={{
                                                            bottom: `${(day.goal_seconds / max) * 100}%`,
                                                        }}
                                                    />
                                                )}
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

function dayTitle(day: ReportDay): string {
    const parts = [`${formatDate(day.date)}: ${formatDuration(day.seconds)}`];

    if (day.goal_seconds !== null) {
        parts.push(`meta ${formatDuration(day.goal_seconds)}`);
    }

    parts.push(day.holiday ?? STATUS[day.status].label.toLowerCase());

    return parts.join(' · ');
}

/**
 * Hours and minutes that fit in a calendar cell, e.g. "7:42".
 */
function compactClock(seconds: number): string {
    const minutes = Math.floor(seconds / 60);

    return `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')}`;
}

function plural(count: number, one: string, many: string): string {
    return `${count} ${count === 1 ? one : many}`;
}

/**
 * The daily goal over the period: how many business days reached it, which
 * fell short and by how much, and which weekends or holidays were worked.
 */
function GoalSection({
    goals,
    days,
}: {
    goals: Props['goals'];
    days: ReportDay[];
}) {
    const judged = goals.met + goals.missed;
    const missed = days.filter((day) => day.status === 'missed').reverse();
    const offWorked = days
        .filter((day) => day.status === 'off_worked')
        .reverse();

    return (
        <section className="space-y-4" aria-labelledby="goal-title">
            <div>
                <h2 id="goal-title" className="text-lg font-semibold">
                    Meta diária
                </h2>
                <p className="text-sm text-muted-foreground">
                    Avaliada só em dias úteis (segunda a sexta, sem feriados
                    nacionais). Um dia atinge a meta quando cada categoria com
                    meta chega à sua.
                </p>
            </div>

            {!goals.has_goal && (
                <Card>
                    <CardContent className="flex flex-wrap items-center justify-between gap-3 text-sm">
                        <span className="text-muted-foreground">
                            Nenhuma categoria tem meta diária. Defina uma para
                            acompanhar os dias úteis.
                        </span>
                        <Button asChild size="sm" variant="outline">
                            <Link href={time.categories.index()}>
                                Definir metas
                            </Link>
                        </Button>
                    </CardContent>
                </Card>
            )}

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {goals.has_goal && (
                    <>
                        <StatCard
                            title="Meta atingida"
                            icon={Target}
                            value={
                                <span className="text-green-700 dark:text-green-400">
                                    {goals.met}
                                    <span className="text-base font-normal text-muted-foreground">
                                        {' '}
                                        de {judged} dia(s) útil(eis)
                                    </span>
                                </span>
                            }
                            hint={
                                <div className="space-y-1.5">
                                    {goals.rate !== null && (
                                        <ProgressBar
                                            percent={goals.rate / 100}
                                            label="Dias úteis com a meta atingida"
                                        />
                                    )}
                                    <div>
                                        {goals.rate !== null
                                            ? `${formatPercent(goals.rate)} dos dias úteis avaliados`
                                            : 'Nenhum dia útil avaliado ainda'}
                                        {goals.pending > 0 &&
                                            ' · hoje ainda em andamento'}
                                    </div>
                                </div>
                            }
                        />
                        <StatCard
                            title="Abaixo da meta"
                            icon={TriangleAlert}
                            value={
                                <span
                                    className={cn(
                                        goals.missed > 0 &&
                                            'text-red-700 dark:text-red-400',
                                    )}
                                >
                                    {plural(
                                        goals.missed,
                                        'dia útil',
                                        'dias úteis',
                                    )}
                                </span>
                            }
                            hint={
                                goals.missed > 0
                                    ? `Faltaram ${formatDuration(goals.shortfall_seconds)} no total.`
                                    : 'Nenhum dia útil abaixo da meta.'
                            }
                        />
                        <StatCard
                            title="Sequência atual"
                            icon={Flame}
                            value={plural(
                                goals.current_streak,
                                'dia útil',
                                'dias úteis',
                            )}
                            hint={`Melhor sequência no período: ${goals.best_streak}.`}
                        />
                    </>
                )}
                <StatCard
                    title="Fins de semana e feriados"
                    icon={CalendarX2}
                    value={
                        <span
                            className={cn(
                                goals.off_days_worked > 0 &&
                                    'text-amber-700 dark:text-amber-400',
                            )}
                        >
                            {plural(
                                goals.off_days_worked,
                                'dia trabalhado',
                                'dias trabalhados',
                            )}
                        </span>
                    }
                    hint={
                        goals.off_days_worked > 0
                            ? `${formatDuration(goals.off_seconds)} fora dos dias úteis.`
                            : 'Nenhum tempo em dia não útil.'
                    }
                />
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Calendário da meta</CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                    <GoalCalendar days={days} />
                    <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        {(
                            [
                                'met',
                                'missed',
                                'pending',
                                'off_worked',
                                'off',
                            ] as const
                        ).map((status) => (
                            <li
                                key={status}
                                className="flex items-center gap-1.5"
                            >
                                <span
                                    className={cn(
                                        'size-3 rounded-[2px] border',
                                        STATUS[status].cell,
                                    )}
                                />
                                {STATUS[status].label}
                            </li>
                        ))}
                    </ul>
                </CardContent>
            </Card>

            <div className="grid gap-4 xl:grid-cols-2">
                {goals.has_goal && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Dias úteis abaixo da meta</CardTitle>
                            <CardDescription>
                                Do mais recente ao mais antigo.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <DayList
                                days={missed}
                                empty="Todos os dias úteis avaliados atingiram a meta."
                                detail={(day) =>
                                    `faltou ${formatDuration(day.shortfall_seconds)}`
                                }
                                tone="text-red-700 dark:text-red-400"
                            />
                        </CardContent>
                    </Card>
                )}
                <Card>
                    <CardHeader>
                        <CardTitle>Dias não úteis trabalhados</CardTitle>
                        <CardDescription>
                            Fins de semana e feriados nacionais com tempo
                            lançado.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <DayList
                            days={offWorked}
                            empty="Nenhum fim de semana ou feriado trabalhado no período."
                            detail={(day) => formatDuration(day.seconds)}
                            tone="text-amber-700 dark:text-amber-400"
                        />
                    </CardContent>
                </Card>
            </div>
        </section>
    );
}

function DayList({
    days,
    empty,
    detail,
    tone,
}: {
    days: ReportDay[];
    empty: string;
    detail: (day: ReportDay) => string;
    tone: string;
}) {
    if (days.length === 0) {
        return <p className="text-sm text-muted-foreground">{empty}</p>;
    }

    return (
        <ul className="max-h-96 divide-y overflow-y-auto text-sm">
            {days.map((day) => (
                <li key={day.date}>
                    <Link
                        href={time.index({
                            query: { view: 'week', date: day.date },
                        })}
                        className="block space-y-1 rounded-sm px-2 py-2 hover:bg-muted"
                    >
                        <div className="flex items-baseline justify-between gap-2">
                            <span>
                                <span className="tabular-nums">
                                    {formatDate(day.date)}
                                </span>{' '}
                                <span className="text-muted-foreground">
                                    {day.holiday ?? weekday(day.date)}
                                </span>
                            </span>
                            <span
                                className={cn(
                                    'shrink-0 font-medium tabular-nums',
                                    tone,
                                )}
                            >
                                {detail(day)}
                            </span>
                        </div>
                        <ul className="flex flex-wrap gap-x-3 text-xs text-muted-foreground">
                            {day.categories
                                .filter(
                                    (row) =>
                                        row.seconds > 0 ||
                                        row.goal_seconds !== null,
                                )
                                .map((row) => (
                                    <li
                                        key={row.category_id}
                                        className="tabular-nums"
                                    >
                                        {row.name}:{' '}
                                        {formatDuration(row.seconds)}
                                        {row.goal_seconds !== null &&
                                            ` / ${formatDuration(row.goal_seconds)}`}
                                    </li>
                                ))}
                        </ul>
                    </Link>
                </li>
            ))}
        </ul>
    );
}

const WEEK_HEADER = ['seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'];

/**
 * One calendar per month of the period, every day colored by its verdict.
 */
function GoalCalendar({ days }: { days: ReportDay[] }) {
    const months: [string, ReportDay[]][] = [];

    for (const day of days) {
        const month = day.date.slice(0, 7);

        if (months.at(-1)?.[0] !== month) {
            months.push([month, []]);
        }

        months[months.length - 1][1].push(day);
    }

    return (
        <div
            className={cn(
                'grid gap-6',
                months.length === 1
                    ? 'max-w-xl'
                    : 'sm:grid-cols-2 xl:grid-cols-3',
            )}
        >
            {months.map(([month, monthDays]) => (
                <div key={month} className="space-y-2">
                    <h3 className="text-sm font-medium">
                        {capitalize(formatMonth(month))}
                    </h3>
                    <div className="grid grid-cols-7 gap-1 text-center text-xs">
                        {WEEK_HEADER.map((label) => (
                            <span key={label} className="text-muted-foreground">
                                {label}
                            </span>
                        ))}
                        {Array.from({
                            length: (weekdayIndex(monthDays[0].date) + 6) % 7,
                        }).map((_, index) => (
                            <span key={`blank-${index}`} />
                        ))}
                        {monthDays.map((day) => (
                            <Link
                                key={day.date}
                                href={time.index({
                                    query: { view: 'week', date: day.date },
                                })}
                                title={dayTitle(day)}
                                aria-label={`${shortWeekday(day.date)}, ${dayTitle(day)}`}
                                className={cn(
                                    'flex aspect-square min-h-9 flex-col items-center justify-center rounded-md leading-tight hover:opacity-80',
                                    STATUS[day.status].cell,
                                )}
                            >
                                <span className="font-medium tabular-nums">
                                    {Number(day.date.slice(8))}
                                </span>
                                {day.seconds > 0 && (
                                    <span className="text-[10px] tabular-nums opacity-80">
                                        {compactClock(day.seconds)}
                                    </span>
                                )}
                            </Link>
                        ))}
                    </div>
                </div>
            ))}
        </div>
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
