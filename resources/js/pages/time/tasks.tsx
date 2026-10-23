import { Head, Link, router } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    BarChart3,
    CheckCircle2,
    ListTodo,
    Pencil,
    Play,
    Plus,
    RotateCcw,
    Trash2,
} from 'lucide-react';
import { ConfirmDialog } from '@/components/finance/confirm-dialog';
import { EmptyState } from '@/components/finance/empty-state';
import { PageHeader } from '@/components/finance/page-header';
import {
    GOAL_LABELS,
    TaskFormDialog,
} from '@/components/time/task-form-dialog';
import { TaskProgress } from '@/components/time/task-progress';
import { useTimerActions } from '@/components/time/timer';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import time from '@/routes/time';
import type { Task, TaskStatus } from '@/types';

type Props = {
    filters: { status: TaskStatus };
    counts: Record<TaskStatus, number>;
    tasks: Task[];
};

const TABS: [TaskStatus, string][] = [
    ['active', 'Ativas'],
    ['completed', 'Concluídas'],
    ['archived', 'Arquivadas'],
];

const EMPTY: Record<TaskStatus, string> = {
    active: 'Crie uma tarefa com meta de tempo, de resultado ou sem meta.',
    completed: 'Tarefas concluídas aparecem aqui, com o histórico preservado.',
    archived:
        'Arquivar esconde a tarefa sem apagar o tempo lançado nem as sessões.',
};

export default function TimeTasks({ filters, counts, tasks }: Props) {
    const timer = useTimerActions();
    const post = (url: string, method: 'post' | 'delete' = 'post') =>
        router.visit(url, { method, preserveScroll: true });

    return (
        <>
            <Head title="Tarefas" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Tarefas"
                    description="Metas de tempo usam só o tempo lançado na planilha; o cronômetro não conta."
                >
                    <TaskFormDialog
                        trigger={
                            <Button>
                                <Plus />
                                Nova tarefa
                            </Button>
                        }
                    />
                </PageHeader>

                <nav
                    className="inline-flex w-fit rounded-lg bg-muted p-1 text-sm"
                    aria-label="Situação das tarefas"
                >
                    {TABS.map(([status, label]) => (
                        <Link
                            key={status}
                            href={time.tasks.index({ query: { status } })}
                            preserveScroll
                            aria-current={
                                filters.status === status ? 'page' : undefined
                            }
                            className={cn(
                                'rounded-md px-3 py-1.5 font-medium',
                                filters.status === status
                                    ? 'bg-background shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {label}{' '}
                            <span className="text-muted-foreground tabular-nums">
                                {counts[status]}
                            </span>
                        </Link>
                    ))}
                </nav>

                {tasks.length === 0 ? (
                    <EmptyState
                        icon={ListTodo}
                        title="Nenhuma tarefa aqui"
                        description={EMPTY[filters.status]}
                    />
                ) : (
                    <ul className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {tasks.map((task) => (
                            <li key={task.id}>
                                <Card className="h-full gap-3 py-4">
                                    <CardContent className="flex h-full flex-col gap-3 px-4">
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="min-w-0 space-y-1">
                                                <h2 className="font-medium break-words">
                                                    {task.title}
                                                </h2>
                                                <Badge variant="secondary">
                                                    {
                                                        GOAL_LABELS[
                                                            task.goal_type
                                                        ]
                                                    }
                                                </Badge>
                                            </div>
                                            {task.status === 'active' && (
                                                <Button
                                                    size="icon"
                                                    variant="outline"
                                                    className="shrink-0"
                                                    disabled={timer.processing}
                                                    onClick={() =>
                                                        timer.start(task.id)
                                                    }
                                                    aria-label={`Iniciar cronômetro de ${task.title}`}
                                                >
                                                    <Play />
                                                </Button>
                                            )}
                                        </div>
                                        {task.description && (
                                            <p className="text-sm whitespace-pre-line text-muted-foreground">
                                                {task.description}
                                            </p>
                                        )}
                                        <TaskProgress task={task} />
                                        <div className="mt-auto flex flex-wrap gap-1 pt-2">
                                            <TaskFormDialog
                                                task={task}
                                                trigger={
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                    >
                                                        <Pencil />
                                                        Editar
                                                    </Button>
                                                }
                                            />
                                            {task.status === 'active' && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        post(
                                                            time.tasks.complete.url(
                                                                task.id,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <CheckCircle2 />
                                                    Concluir
                                                </Button>
                                            )}
                                            {task.status === 'completed' && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        post(
                                                            time.tasks.reopen.url(
                                                                task.id,
                                                            ),
                                                            'delete',
                                                        )
                                                    }
                                                >
                                                    <RotateCcw />
                                                    Reabrir
                                                </Button>
                                            )}
                                            {task.status === 'archived' ? (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        post(
                                                            time.tasks.restore.url(
                                                                task.id,
                                                            ),
                                                            'delete',
                                                        )
                                                    }
                                                >
                                                    <ArchiveRestore />
                                                    Restaurar
                                                </Button>
                                            ) : (
                                                <ConfirmDialog
                                                    url={time.tasks.archive.url(
                                                        task.id,
                                                    )}
                                                    method="post"
                                                    destructive={false}
                                                    title="Arquivar tarefa?"
                                                    description={`“${task.title}” sai das listas e da planilha, mas o tempo lançado e as sessões continuam nos relatórios. Um cronômetro aberto desta tarefa será parado.`}
                                                    confirmLabel="Arquivar"
                                                    trigger={
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                        >
                                                            <Archive />
                                                            Arquivar
                                                        </Button>
                                                    }
                                                />
                                            )}
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                asChild
                                            >
                                                <Link
                                                    href={time.reports.index({
                                                        query: {
                                                            task: task.id,
                                                        },
                                                    })}
                                                >
                                                    <BarChart3 />
                                                    Relatório
                                                </Link>
                                            </Button>
                                            {task.has_history ? (
                                                <Tooltip>
                                                    <TooltipTrigger asChild>
                                                        <span>
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                disabled
                                                                aria-label={`Excluir ${task.title} (indisponível: há histórico)`}
                                                            >
                                                                <Trash2 />
                                                            </Button>
                                                        </span>
                                                    </TooltipTrigger>
                                                    <TooltipContent>
                                                        Tem tempo lançado ou
                                                        sessões: arquive para
                                                        manter o histórico.
                                                    </TooltipContent>
                                                </Tooltip>
                                            ) : (
                                                <ConfirmDialog
                                                    url={time.tasks.destroy.url(
                                                        task.id,
                                                    )}
                                                    title="Excluir tarefa?"
                                                    description={`“${task.title}” será excluída definitivamente. Ela não tem tempo lançado nem sessões do cronômetro.`}
                                                    confirmLabel="Excluir"
                                                    trigger={
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            className="text-destructive"
                                                            aria-label={`Excluir ${task.title}`}
                                                        >
                                                            <Trash2 />
                                                        </Button>
                                                    }
                                                />
                                            )}
                                        </div>
                                    </CardContent>
                                </Card>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
            {timer.dialog}
        </>
    );
}

TimeTasks.layout = {
    breadcrumbs: [
        { title: 'Tarefas e tempo', href: time.index() },
        { title: 'Tarefas', href: time.tasks.index() },
    ],
};
