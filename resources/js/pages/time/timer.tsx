import { Head } from '@inertiajs/react';
import { ClipboardPlus, History, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/finance/confirm-dialog';
import { EmptyState } from '@/components/finance/empty-state';
import { FilterSelect } from '@/components/finance/filter-select';
import { PageHeader } from '@/components/finance/page-header';
import { Pagination } from '@/components/finance/pagination';
import { StatCard } from '@/components/finance/stat-card';
import { EntryFormDialog } from '@/components/time/entry-form-dialog';
import {
    ElapsedClock,
    TimerControls,
    TimerPanel,
    useTimerActions,
    useTimerSync,
} from '@/components/time/timer';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useQueryFilters } from '@/hooks/use-query-filters';
import { formatDate, formatDateTime } from '@/lib/format';
import { formatElapsed, sessionDraft } from '@/lib/time';
import time from '@/routes/time';
import type { EntryDraft, Paginated, TaskOption, TimerSession } from '@/types';

type Props = {
    filters: { task: number | null };
    open_sessions: TimerSession[];
    sessions: Paginated<TimerSession>;
    tasks: TaskOption[];
    all_tasks: { id: number; title: string }[];
    measured: { today_seconds: number; week_seconds: number };
};

export default function TimeTimer({
    filters,
    open_sessions,
    sessions,
    tasks,
    all_tasks,
    measured,
}: Props) {
    const actions = useTimerActions();
    const filter = useQueryFilters(time.timer.index.url(), filters);
    const [draft, setDraft] = useState<EntryDraft | null>(null);
    const paused = open_sessions.filter(
        (session) => session.status === 'paused',
    );

    useTimerSync(['timer', 'open_sessions', 'sessions', 'measured']);

    return (
        <>
            <Head title="Cronômetro" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Cronômetro"
                    description="Sessões medem o tempo, mas não entram na planilha nem nos relatórios. Para registrar, lance o tempo explicitamente."
                />

                <div className="grid gap-4 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>Cronômetro atual</CardTitle>
                            <CardDescription>
                                O tempo é calculado pelo servidor: atualizar a
                                página ou fechar o navegador não o perde.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <TimerPanel tasks={tasks} />
                        </CardContent>
                    </Card>
                    <div className="grid gap-4">
                        <StatCard
                            title="Medido hoje (cronômetro)"
                            value={formatElapsed(measured.today_seconds)}
                            hint="Não somado ao tempo registrado."
                        />
                        <StatCard
                            title="Medido na semana (cronômetro)"
                            value={formatElapsed(measured.week_seconds)}
                            hint="Não somado ao tempo registrado."
                        />
                    </div>
                </div>

                {paused.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Sessões pausadas</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul className="divide-y">
                                {paused.map((session) => (
                                    <li
                                        key={session.id}
                                        className="flex flex-wrap items-center justify-between gap-3 py-2"
                                    >
                                        <div>
                                            <div className="font-medium">
                                                {session.task.title}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                Início{' '}
                                                {formatDateTime(
                                                    session.started_at,
                                                )}
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <ElapsedClock session={session} />
                                            <TimerControls
                                                session={session}
                                                actions={actions}
                                                size="sm"
                                            />
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}

                <div className="flex flex-wrap items-end justify-between gap-4">
                    <h2 className="text-lg font-semibold">
                        Histórico de sessões
                    </h2>
                    <FilterSelect
                        id="timer-filter-task"
                        label="Tarefa"
                        value={filters.task ?? ''}
                        options={[
                            ['', 'Todas'],
                            ...all_tasks.map((task): [string, string] => [
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
                </div>

                {sessions.data.length === 0 ? (
                    <EmptyState
                        icon={History}
                        title="Nenhuma sessão encerrada"
                        description="Sessões paradas ficam aqui, separadas do tempo lançado."
                    />
                ) : (
                    <Card className="py-0">
                        <CardContent className="overflow-x-auto px-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="pl-4">
                                            Tarefa
                                        </TableHead>
                                        <TableHead>Início</TableHead>
                                        <TableHead>Fim</TableHead>
                                        <TableHead className="text-right">
                                            Medido
                                        </TableHead>
                                        <TableHead className="pr-4">
                                            <span className="sr-only">
                                                Ações
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {sessions.data.map((session) => (
                                        <TableRow key={session.id}>
                                            <TableCell className="pl-4 font-medium">
                                                {session.task.title}
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap">
                                                {formatDateTime(
                                                    session.started_at,
                                                )}
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap">
                                                {session.ended_at
                                                    ? formatDateTime(
                                                          session.ended_at,
                                                      )
                                                    : '—'}
                                            </TableCell>
                                            <TableCell className="text-right font-mono tabular-nums">
                                                {formatElapsed(
                                                    session.elapsed_seconds,
                                                )}
                                            </TableCell>
                                            <TableCell className="pr-4">
                                                <div className="flex justify-end gap-0.5">
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        disabled={
                                                            session.task
                                                                .archived
                                                        }
                                                        onClick={() =>
                                                            setDraft(
                                                                sessionDraft(
                                                                    session,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        <ClipboardPlus />
                                                        Lançar na planilha
                                                    </Button>
                                                    <ConfirmDialog
                                                        url={time.timer.destroy.url(
                                                            session.id,
                                                        )}
                                                        title="Excluir sessão?"
                                                        description={`A sessão de “${session.task.title}” de ${formatDate(session.started_on)} será excluída. O tempo lançado na planilha não muda.`}
                                                        confirmLabel="Excluir"
                                                        trigger={
                                                            <Button
                                                                size="icon"
                                                                variant="ghost"
                                                                className="size-8 text-destructive"
                                                                aria-label="Excluir sessão"
                                                            >
                                                                <Trash2 className="size-4" />
                                                            </Button>
                                                        }
                                                    />
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}

                <Pagination page={sessions} />
            </div>

            {draft && (
                <EntryFormDialog
                    tasks={tasks}
                    draft={draft}
                    open
                    onOpenChange={(open) => !open && setDraft(null)}
                />
            )}
            {actions.dialog}
        </>
    );
}

TimeTimer.layout = {
    breadcrumbs: [
        { title: 'Tarefas e tempo', href: time.index() },
        { title: 'Cronômetro', href: time.timer.index() },
    ],
};
