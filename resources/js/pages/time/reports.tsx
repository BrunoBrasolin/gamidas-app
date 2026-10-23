import { Head, Link } from '@inertiajs/react';
import { BarChart3, Target } from 'lucide-react';
import { EmptyState } from '@/components/finance/empty-state';
import { FilterSelect } from '@/components/finance/filter-select';
import { PageHeader } from '@/components/finance/page-header';
import { StatCard } from '@/components/finance/stat-card';
import { ProgressBar, TaskProgress } from '@/components/time/task-progress';
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
import { formatMinutes, shiftDay, weekday, weekdayIndex } from '@/lib/time';
import { cn } from '@/lib/utils';
import time from '@/routes/time';
import type { Task, TaskOption, TaskStatus } from '@/types';

type Props = {
    filters: { from: string; to: string; task: number | null };
    fixed: {
        today_minutes: number;
        week_minutes: number;
        month_minutes: number;
    };
    period: {
        days: number;
        days_with_entries: number;
        total_minutes: number;
        average_minutes: number;
        average_active_minutes: number;
        previous: { from: string; to: string; total_minutes: number };
    };
    by_day: { date: string; minutes: number }[];
    by_task: {
        task_id: number;
        title: string;
        status: TaskStatus | null;
        minutes: number;
        share: number;
    }[];
    goals: Task[];
    task_counts: Record<TaskStatus, number>;
    heatmap: { from: string; to: string; days: Record<string, number> };
    task_options: TaskOption[];
};

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
    by_task,
    goals,
    task_counts,
    heatmap,
    task_options,
}: Props) {
    const filter = useQueryFilters(time.reports.index.url(), filters);
    const max = Math.max(60, ...by_day.map((day) => day.minutes));
    const difference = period.total_minutes - period.previous.total_minutes;

    return (
        <>
            <Head title="Relatórios de tempo" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Relatórios de tempo"
                    description="Calculados só com o tempo lançado manualmente. Medem o tempo registrado, não a produtividade."
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
                        id="report-task"
                        label="Tarefa"
                        value={filters.task ?? ''}
                        options={[
                            ['', 'Todas'],
                            ...task_options.map((task): [string, string] => [
                                String(task.id),
                                task.title,
                            ]),
                        ]}
                        onChange={(value) =>
                            filter({
                                task: value === '' ? null : Number(value),
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
                        value={formatMinutes(fixed.today_minutes)}
                    />
                    <StatCard
                        title="Nesta semana"
                        value={formatMinutes(fixed.week_minutes)}
                    />
                    <StatCard
                        title="Neste mês"
                        value={formatMinutes(fixed.month_minutes)}
                    />
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        title={`Total de ${formatShortDate(filters.from)} a ${formatShortDate(filters.to)}`}
                        value={formatMinutes(period.total_minutes)}
                        hint={
                            <>
                                Período anterior (
                                {formatShortDate(period.previous.from)}–
                                {formatShortDate(period.previous.to)}):{' '}
                                {formatMinutes(period.previous.total_minutes)}
                                {difference !== 0 &&
                                    ` (${difference > 0 ? '+' : '−'}${formatMinutes(Math.abs(difference))})`}
                            </>
                        }
                    />
                    <StatCard
                        title="Média por dia"
                        value={formatMinutes(period.average_minutes)}
                        hint={`Em ${period.days} dia(s) do período.`}
                    />
                    <StatCard
                        title="Média nos dias com registro"
                        value={formatMinutes(period.average_active_minutes)}
                        hint={`${period.days_with_entries} de ${period.days} dia(s) com tempo lançado.`}
                    />
                    <StatCard
                        title="Tarefas"
                        value={`${task_counts.active} ativas`}
                        hint={`${task_counts.completed} concluídas · ${task_counts.archived} arquivadas`}
                    />
                </div>

                {period.total_minutes === 0 ? (
                    <EmptyState
                        icon={BarChart3}
                        title="Nenhum tempo lançado no período"
                        description="Lance tempo na planilha ou escolha outro período."
                    >
                        <Button asChild>
                            <Link href={time.sheet()}>Abrir a planilha</Link>
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
                                                href={time.days.show(day.date)}
                                                className="flex h-full items-end rounded-sm hover:bg-muted"
                                                title={`${formatDate(day.date)}: ${formatMinutes(day.minutes)}`}
                                                aria-label={`${weekday(day.date)}, ${formatDate(day.date)}: ${formatMinutes(day.minutes)}`}
                                            >
                                                <span
                                                    className="w-full rounded-sm bg-primary/80"
                                                    style={{
                                                        height: `${(day.minutes / max) * 100}%`,
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
                                <CardTitle>Tempo por tarefa</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <ul className="space-y-3 text-sm">
                                    {by_task.map((row) => (
                                        <li
                                            key={row.task_id}
                                            className="space-y-1"
                                        >
                                            <div className="flex justify-between gap-2">
                                                <span className="truncate">
                                                    {row.title}
                                                    {row.status !==
                                                        'active' && (
                                                        <span className="ml-1 text-xs text-muted-foreground">
                                                            (
                                                            {row.status ===
                                                            'archived'
                                                                ? 'arquivada'
                                                                : 'concluída'}
                                                            )
                                                        </span>
                                                    )}
                                                </span>
                                                <span className="shrink-0 tabular-nums">
                                                    {formatMinutes(row.minutes)}{' '}
                                                    · {formatPercent(row.share)}
                                                </span>
                                            </div>
                                            <ProgressBar
                                                percent={row.share / 100}
                                                label={`Parcela de ${row.title}`}
                                            />
                                        </li>
                                    ))}
                                </ul>
                            </CardContent>
                        </Card>
                    </div>
                )}

                <Heatmap heatmap={heatmap} />

                <Card>
                    <CardHeader>
                        <CardTitle>Metas</CardTitle>
                        <CardDescription>
                            Progresso com todo o tempo já lançado em cada
                            tarefa.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {goals.length === 0 ? (
                            <EmptyState
                                icon={Target}
                                title="Nenhuma tarefa com meta"
                                description="Defina uma meta de tempo ou de resultado nas tarefas."
                            />
                        ) : (
                            <ul className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                {goals.map((task) => (
                                    <li
                                        key={task.id}
                                        className="space-y-2 rounded-lg border p-3"
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="font-medium">
                                                {task.title}
                                            </span>
                                            {task.status !== 'active' && (
                                                <span className="text-xs text-muted-foreground">
                                                    {task.status === 'archived'
                                                        ? 'arquivada'
                                                        : 'concluída'}
                                                </span>
                                            )}
                                        </div>
                                        <TaskProgress task={task} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
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

function level(minutes: number): number {
    if (minutes <= 0) {
        return 0;
    }

    return minutes < 60 ? 1 : minutes < 180 ? 2 : minutes < 300 ? 3 : 4;
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
                <CardTitle>Dias com tempo registrado</CardTitle>
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
                                const minutes = heatmap.days[day] ?? 0;

                                return (
                                    <Link
                                        key={day}
                                        href={time.days.show(day)}
                                        title={`${formatDate(day)}: ${minutes > 0 ? formatMinutes(minutes) : 'sem registro'}`}
                                        tabIndex={-1}
                                        className={cn(
                                            'size-3 rounded-[2px]',
                                            day > now
                                                ? 'opacity-30'
                                                : LEVELS[level(minutes)],
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
        { title: 'Tarefas e tempo', href: time.index() },
        { title: 'Relatórios', href: time.reports.index() },
    ],
};
