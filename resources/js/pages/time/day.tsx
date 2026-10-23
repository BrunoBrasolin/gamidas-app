import { Form, Head, Link, router } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    ClipboardPlus,
    Copy,
    Pencil,
    Plus,
    Timer,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/finance/confirm-dialog';
import { PageHeader } from '@/components/finance/page-header';
import InputError from '@/components/input-error';
import { DurationInput } from '@/components/time/duration-input';
import { EntryFormDialog } from '@/components/time/entry-form-dialog';
import { NoteEditor } from '@/components/time/note-editor';
import { ViewSwitch } from '@/components/time/view-switch';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { capitalize, formatDate, today } from '@/lib/format';
import {
    formatClock,
    formatElapsed,
    formatMinutes,
    parseDuration,
    sessionDraft,
    weekday,
} from '@/lib/time';
import time from '@/routes/time';
import type {
    DailyNote,
    EntryDraft,
    TaskOption,
    TaskStatus,
    TimeEntry,
    TimerSession,
} from '@/types';

type DayEntry = TimeEntry & { task_title: string; task_status: TaskStatus };

type Props = {
    date: string;
    previous: string;
    next: string;
    is_today: boolean;
    entries: DayEntry[];
    by_task: { task_id: number; title: string; minutes: number }[];
    total_minutes: number;
    note: DailyNote;
    copy_source: { date: string; count: number } | null;
    timer_sessions: TimerSession[];
    task_options: TaskOption[];
};

export default function TimeDay({
    date,
    previous,
    next,
    is_today,
    entries,
    by_task,
    total_minutes,
    note,
    copy_source,
    timer_sessions,
    task_options,
}: Props) {
    const [draft, setDraft] = useState<EntryDraft | null>(null);
    const startable = task_options.filter((task) => task.status !== 'archived');

    return (
        <>
            <Head title={`Dia ${formatDate(date)}`} />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title={`${capitalize(weekday(date))}, ${formatDate(date)}`}
                    description={
                        is_today
                            ? 'Hoje. Lance o tempo de cada tarefa e escreva a nota do dia.'
                            : 'Lance o tempo de cada tarefa e escreva a nota do dia.'
                    }
                >
                    <div className="flex items-center gap-1">
                        <Button variant="outline" size="icon" asChild>
                            <Link
                                href={time.days.show(previous)}
                                aria-label="Dia anterior"
                                preserveScroll
                            >
                                <ChevronLeft />
                            </Link>
                        </Button>
                        <label className="relative">
                            <span className="sr-only">Escolher dia</span>
                            <Input
                                type="date"
                                value={date}
                                min="2000-01-01"
                                max="2100-12-31"
                                onChange={(event) =>
                                    event.target.value &&
                                    router.get(
                                        time.days.show.url(event.target.value),
                                    )
                                }
                                className="w-40"
                            />
                        </label>
                        <Button variant="outline" size="icon" asChild>
                            <Link
                                href={time.days.show(next)}
                                aria-label="Próximo dia"
                                preserveScroll
                            >
                                <ChevronRight />
                            </Link>
                        </Button>
                    </div>
                    {!is_today && (
                        <Button variant="ghost" asChild>
                            <Link href={time.days.show(today())}>Hoje</Link>
                        </Button>
                    )}
                    <ViewSwitch date={date} />
                </PageHeader>

                <div className="grid gap-6 xl:grid-cols-5">
                    <div className="flex flex-col gap-4 xl:col-span-3">
                        <Card>
                            <CardHeader className="flex-row flex-wrap items-start justify-between gap-2">
                                <div className="space-y-1.5">
                                    <CardTitle>Tempo lançado</CardTitle>
                                    <CardDescription>
                                        Total do dia:{' '}
                                        <strong className="text-foreground">
                                            {formatMinutes(total_minutes)}
                                        </strong>
                                    </CardDescription>
                                </div>
                                {copy_source && (
                                    <ConfirmDialog
                                        url={time.days.copy.url(date, {
                                            query: { from: copy_source.date },
                                        })}
                                        method="post"
                                        destructive={false}
                                        title="Copiar lançamentos?"
                                        description={`${copy_source.count} lançamento(s) de ${formatDate(copy_source.date)} serão copiados para ${formatDate(date)}. Lançamentos iguais já existentes não são duplicados.`}
                                        confirmLabel="Copiar"
                                        trigger={
                                            <Button variant="outline" size="sm">
                                                <Copy />
                                                Copiar de{' '}
                                                {formatDate(copy_source.date)}
                                            </Button>
                                        }
                                    />
                                )}
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <QuickAdd date={date} tasks={startable} />

                                {entries.length === 0 ? (
                                    <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">
                                        Nenhum tempo lançado neste dia.
                                    </p>
                                ) : (
                                    <ul className="divide-y rounded-md border">
                                        {entries.map((entry) => (
                                            <EntryRow
                                                key={entry.id}
                                                entry={entry}
                                                tasks={task_options}
                                            />
                                        ))}
                                    </ul>
                                )}

                                {by_task.length > 0 && (
                                    <div className="space-y-2">
                                        <h3 className="text-sm font-medium">
                                            Por tarefa
                                        </h3>
                                        <ul className="space-y-1 text-sm">
                                            {by_task.map((row) => (
                                                <li
                                                    key={row.task_id}
                                                    className="flex justify-between gap-2"
                                                >
                                                    <span className="truncate">
                                                        {row.title}
                                                    </span>
                                                    <span className="tabular-nums">
                                                        {formatMinutes(
                                                            row.minutes,
                                                        )}
                                                    </span>
                                                </li>
                                            ))}
                                            <li className="flex justify-between gap-2 border-t pt-1 font-semibold">
                                                <span>Total</span>
                                                <span className="tabular-nums">
                                                    {formatMinutes(
                                                        total_minutes,
                                                    )}
                                                </span>
                                            </li>
                                        </ul>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {timer_sessions.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <Timer className="size-4" aria-hidden />
                                        Medições do cronômetro
                                    </CardTitle>
                                    <CardDescription>
                                        Não fazem parte do total. Use “Lançar”
                                        para registrar o tempo revisando os
                                        valores.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <ul className="divide-y text-sm">
                                        {timer_sessions.map((session) => (
                                            <li
                                                key={session.id}
                                                className="flex items-center justify-between gap-2 py-2"
                                            >
                                                <span className="truncate">
                                                    {session.task.title}
                                                </span>
                                                <span className="flex items-center gap-2">
                                                    <span className="font-mono tabular-nums">
                                                        {formatElapsed(
                                                            session.elapsed_seconds,
                                                        )}
                                                    </span>
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
                                                        Lançar
                                                    </Button>
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    <Card className="xl:col-span-2">
                        <CardHeader>
                            <CardTitle>Nota do dia</CardTitle>
                            <CardDescription>
                                Markdown com salvamento automático.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <NoteEditor key={date} note={note} />
                        </CardContent>
                    </Card>
                </div>
            </div>

            {draft && (
                <EntryFormDialog
                    tasks={task_options}
                    draft={draft}
                    open
                    onOpenChange={(open) => !open && setDraft(null)}
                />
            )}
        </>
    );
}

function QuickAdd({ date, tasks }: { date: string; tasks: TaskOption[] }) {
    if (tasks.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                <Link href={time.tasks.index()} className="underline">
                    Crie uma tarefa
                </Link>{' '}
                para lançar tempo.
            </p>
        );
    }

    return (
        <Form
            {...time.entries.store.form()}
            options={{ preserveScroll: true }}
            resetOnSuccess={['minutes', 'description']}
            className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_7rem_minmax(0,1fr)_auto] sm:items-start"
        >
            {({ errors, processing }) => (
                <>
                    <input type="hidden" name="entry_date" value={date} />
                    <div className="grid gap-1">
                        <label htmlFor="quick-task" className="sr-only">
                            Tarefa
                        </label>
                        <NativeSelect id="quick-task" name="task_id" required>
                            {tasks.map((task) => (
                                <option key={task.id} value={task.id}>
                                    {task.title}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError
                            message={errors.task_id ?? errors.entry_date}
                        />
                    </div>
                    <div className="grid gap-1">
                        <label htmlFor="quick-minutes" className="sr-only">
                            Duração
                        </label>
                        <DurationInput
                            id="quick-minutes"
                            name="minutes"
                            required
                        />
                        <InputError message={errors.minutes} />
                    </div>
                    <div className="grid gap-1">
                        <label htmlFor="quick-description" className="sr-only">
                            Descrição
                        </label>
                        <Input
                            id="quick-description"
                            name="description"
                            maxLength={255}
                            placeholder="Descrição (opcional)"
                        />
                        <InputError message={errors.description} />
                    </div>
                    <Button type="submit" disabled={processing}>
                        {processing ? <Spinner /> : <Plus />}
                        Adicionar
                    </Button>
                </>
            )}
        </Form>
    );
}

/**
 * An entry with its duration editable in place: click (or Enter) to edit,
 * Enter or leaving the field saves, Escape cancels.
 */
function EntryRow({ entry, tasks }: { entry: DayEntry; tasks: TaskOption[] }) {
    const [editing, setEditing] = useState(false);
    const [text, setText] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    function commit() {
        const minutes = parseDuration(text);

        if (minutes === null || minutes < 1) {
            setError('Informe uma duração como 1:30, 1h30 ou 45m.');

            return;
        }

        if (minutes === entry.minutes) {
            setEditing(false);

            return;
        }

        router.put(
            time.entries.update.url(entry.id),
            {
                task_id: entry.task_id,
                entry_date: entry.date,
                minutes,
                description: entry.description ?? '',
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => setEditing(false),
                onError: (errors) =>
                    setError(
                        errors.minutes ??
                            errors.task_id ??
                            'Não foi possível salvar.',
                    ),
            },
        );
    }

    return (
        <li className="flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
            <div className="min-w-0 flex-1">
                <div className="truncate font-medium">
                    {entry.task_title}
                    {entry.task_status === 'archived' && (
                        <span className="ml-1 text-xs text-muted-foreground">
                            (arquivada)
                        </span>
                    )}
                </div>
                {entry.description && (
                    <div className="truncate text-xs text-muted-foreground">
                        {entry.description}
                    </div>
                )}
            </div>
            <div className="flex items-center gap-1">
                {editing ? (
                    <div className="grid gap-1">
                        <Input
                            autoFocus
                            aria-label={`Duração de ${entry.task_title}`}
                            value={text}
                            disabled={saving}
                            aria-invalid={error !== null}
                            onChange={(event) => {
                                setText(event.target.value);
                                setError(null);
                            }}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    event.preventDefault();
                                    commit();
                                } else if (event.key === 'Escape') {
                                    setEditing(false);
                                    setError(null);
                                }
                            }}
                            onBlur={() => !saving && commit()}
                            className="h-8 w-24 text-right"
                        />
                        <InputError message={error ?? undefined} />
                    </div>
                ) : (
                    <button
                        type="button"
                        className="rounded px-2 py-1 font-medium tabular-nums hover:bg-muted"
                        onClick={() => {
                            setText(formatClock(entry.minutes));
                            setEditing(true);
                        }}
                        aria-label={`Editar duração de ${entry.task_title}: ${formatMinutes(entry.minutes)}`}
                    >
                        {formatMinutes(entry.minutes)}
                    </button>
                )}
                <EntryFormDialog
                    tasks={tasks}
                    entry={entry}
                    trigger={
                        <Button
                            size="icon"
                            variant="ghost"
                            className="size-8"
                            aria-label={`Editar lançamento de ${entry.task_title}`}
                        >
                            <Pencil className="size-4" />
                        </Button>
                    }
                />
                <ConfirmDialog
                    url={time.entries.destroy.url(entry.id)}
                    title="Excluir lançamento?"
                    description={`${formatMinutes(entry.minutes)} de “${entry.task_title}” em ${formatDate(entry.date)} serão excluídos.`}
                    confirmLabel="Excluir"
                    trigger={
                        <Button
                            size="icon"
                            variant="ghost"
                            className="size-8 text-destructive"
                            aria-label={`Excluir lançamento de ${entry.task_title}`}
                        >
                            <Trash2 className="size-4" />
                        </Button>
                    }
                />
            </div>
        </li>
    );
}

TimeDay.layout = {
    breadcrumbs: [
        { title: 'Tarefas e tempo', href: time.index() },
        { title: 'Planilha', href: time.sheet() },
    ],
};
