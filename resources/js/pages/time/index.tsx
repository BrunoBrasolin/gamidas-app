import { Head, Link } from '@inertiajs/react';
import { CalendarDays, Clock, ListTodo, NotebookPen, Plus } from 'lucide-react';
import { EmptyState } from '@/components/finance/empty-state';
import { PageHeader } from '@/components/finance/page-header';
import { StatCard } from '@/components/finance/stat-card';
import { EntryFormDialog } from '@/components/time/entry-form-dialog';
import { TaskProgress } from '@/components/time/task-progress';
import { TimerPanel } from '@/components/time/timer';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { capitalize, formatDate, formatShortDate } from '@/lib/format';
import { formatMinutes, shortWeekday, weekday } from '@/lib/time';
import { cn } from '@/lib/utils';
import time from '@/routes/time';
import type { Task, TimeEntry } from '@/types';

type Props = {
    today: string;
    today_minutes: number;
    month_minutes: number;
    week: {
        from: string;
        to: string;
        total_minutes: number;
        days: { date: string; minutes: number }[];
    };
    tasks: Task[];
    recent_entries: (TimeEntry & { task_title: string })[];
    today_note: string | null;
};

export default function TimeOverview({
    today,
    today_minutes,
    month_minutes,
    week,
    tasks,
    recent_entries,
    today_note,
}: Props) {
    const options = tasks.map((task) => ({
        id: task.id,
        title: task.title,
        status: task.status,
    }));
    const max = Math.max(60, ...week.days.map((day) => day.minutes));

    return (
        <>
            <Head title="Tarefas e tempo" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Tarefas e tempo"
                    description={`${capitalize(weekday(today))}, ${formatDate(today)}. Os totais vêm só do tempo lançado manualmente na planilha.`}
                >
                    <Button variant="outline" asChild>
                        <Link href={time.days.show(today)}>
                            <CalendarDays />
                            Abrir o dia
                        </Link>
                    </Button>
                    <EntryFormDialog
                        tasks={options}
                        draft={{ date: today }}
                        trigger={
                            <Button>
                                <Plus />
                                Lançar tempo
                            </Button>
                        }
                    />
                </PageHeader>

                <div className="grid gap-4 sm:grid-cols-3">
                    <StatCard
                        title="Registrado hoje"
                        value={formatMinutes(today_minutes)}
                        icon={Clock}
                    />
                    <StatCard
                        title="Nesta semana"
                        value={formatMinutes(week.total_minutes)}
                        hint={`${formatShortDate(week.from)} a ${formatShortDate(week.to)}`}
                    />
                    <StatCard
                        title="Neste mês"
                        value={formatMinutes(month_minutes)}
                    />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Cronômetro</CardTitle>
                            <CardDescription>
                                Mede o tempo de uma tarefa. Parar não lança nada
                                na planilha.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <TimerPanel tasks={options} />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Semana</CardTitle>
                            <CardDescription>
                                Tempo lançado por dia.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <ul className="grid grid-cols-7 items-end gap-2">
                                {week.days.map((day) => (
                                    <li key={day.date}>
                                        <Link
                                            href={time.days.show(day.date)}
                                            className="group flex flex-col items-center gap-1 text-xs"
                                            aria-label={`${weekday(day.date)} ${formatDate(day.date)}: ${formatMinutes(day.minutes)}`}
                                        >
                                            <span className="text-muted-foreground tabular-nums">
                                                {day.minutes > 0
                                                    ? formatMinutes(day.minutes)
                                                    : '—'}
                                            </span>
                                            <span className="flex h-24 w-full items-end rounded bg-muted/50">
                                                <span
                                                    className="w-full rounded bg-primary/80 group-hover:bg-primary"
                                                    style={{
                                                        height: `${(day.minutes / max) * 100}%`,
                                                    }}
                                                />
                                            </span>
                                            <span
                                                className={cn(
                                                    day.date === today &&
                                                        'font-semibold',
                                                )}
                                            >
                                                {shortWeekday(day.date)}
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader className="flex-row items-center justify-between">
                            <div className="space-y-1.5">
                                <CardTitle>Tarefas ativas</CardTitle>
                                <CardDescription>
                                    Progresso das metas pelo tempo lançado.
                                </CardDescription>
                            </div>
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={time.tasks.index()}>Ver todas</Link>
                            </Button>
                        </CardHeader>
                        <CardContent>
                            {tasks.length === 0 ? (
                                <EmptyState
                                    icon={ListTodo}
                                    title="Nenhuma tarefa ativa"
                                    description="Crie tarefas para cronometrar e lançar seu tempo."
                                >
                                    <Button asChild>
                                        <Link href={time.tasks.index()}>
                                            Criar tarefa
                                        </Link>
                                    </Button>
                                </EmptyState>
                            ) : (
                                <ul className="grid gap-4 sm:grid-cols-2">
                                    {tasks.map((task) => (
                                        <li
                                            key={task.id}
                                            className="space-y-2 rounded-lg border p-3"
                                        >
                                            <div className="font-medium">
                                                {task.title}
                                            </div>
                                            <TaskProgress task={task} />
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>

                    <div className="flex flex-col gap-4">
                        <Card>
                            <CardHeader>
                                <CardTitle>Nota de hoje</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <p className="text-sm whitespace-pre-line text-muted-foreground">
                                    {today_note ?? 'Nenhuma nota escrita hoje.'}
                                </p>
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={time.days.show(today)}>
                                        <NotebookPen />
                                        {today_note
                                            ? 'Abrir nota'
                                            : 'Escrever nota'}
                                    </Link>
                                </Button>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Últimos lançamentos</CardTitle>
                            </CardHeader>
                            <CardContent>
                                {recent_entries.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Nenhum tempo lançado ainda.
                                    </p>
                                ) : (
                                    <ul className="divide-y text-sm">
                                        {recent_entries.map((entry) => (
                                            <li
                                                key={entry.id}
                                                className="flex items-center justify-between gap-2 py-2"
                                            >
                                                <Link
                                                    href={time.days.show(
                                                        entry.date,
                                                    )}
                                                    className="min-w-0 hover:underline"
                                                >
                                                    <div className="truncate font-medium">
                                                        {entry.task_title}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {formatDate(entry.date)}
                                                        {entry.description &&
                                                            ` · ${entry.description}`}
                                                    </div>
                                                </Link>
                                                <span className="shrink-0 tabular-nums">
                                                    {formatMinutes(
                                                        entry.minutes,
                                                    )}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </>
    );
}

TimeOverview.layout = {
    breadcrumbs: [{ title: 'Tarefas e tempo', href: time.index() }],
};
