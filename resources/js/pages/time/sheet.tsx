import { Head, Link, router } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    ListTodo,
    NotebookPen,
    Pencil,
    Plus,
    Trash2,
} from 'lucide-react';
import type { KeyboardEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/finance/confirm-dialog';
import { EmptyState } from '@/components/finance/empty-state';
import { FilterSelect } from '@/components/finance/filter-select';
import { PageHeader } from '@/components/finance/page-header';
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
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { useQueryFilters } from '@/hooks/use-query-filters';
import {
    capitalize,
    formatDate,
    formatMonth,
    formatShortDate,
    today,
} from '@/lib/format';
import { jsonRequest } from '@/lib/http';
import {
    formatClock,
    formatMinutes,
    parseDuration,
    shortWeekday,
    weekday,
} from '@/lib/time';
import { cn } from '@/lib/utils';
import time from '@/routes/time';
import type { DailyNote, TaskOption, TaskStatus, TimeEntry } from '@/types';

type Cell = { minutes: number; entries: TimeEntry[] };

type Props = {
    filters: {
        view: 'week' | 'month';
        date: string;
        task: number | null;
        sort: 'title' | 'total';
    };
    period: { from: string; to: string; previous: string; next: string };
    sheet: {
        days: {
            date: string;
            is_today: boolean;
            total_minutes: number;
            note: string | null;
        }[];
        tasks: {
            id: number;
            title: string;
            status: TaskStatus;
            total_minutes: number;
        }[];
        cells: Record<string, Record<number, Cell>>;
        total_minutes: number;
    };
    task_options: TaskOption[];
};

type Position = { row: number; col: number };

export default function TimeSheet({
    filters,
    period,
    sheet,
    task_options,
}: Props) {
    const filter = useQueryFilters(time.sheet.url(), filters);
    // Selection and editing belong to the period they were made in, so they
    // are dropped when the period or the filters change.
    const periodKey = `${period.from}|${filters.task}|${filters.sort}`;
    const [selection, setSelection] = useState<
        (Position & { key: string }) | null
    >(null);
    const [edit, setEdit] = useState<{
        key: string;
        position: Position;
        text: string;
    } | null>(null);
    const selected = selection?.key === periodKey ? selection : null;
    const editing = edit?.key === periodKey ? edit : null;
    const setSelected = (position: Position) =>
        setSelection({ ...position, key: periodKey });
    const setEditing = (next: { position: Position; text: string } | null) =>
        setEdit(next && { ...next, key: periodKey });
    // Set when Enter, Tab or Escape already handled the edit, so the blur
    // caused by the input unmounting doesn't save a second time.
    const handled = useRef(false);
    const [saving, setSaving] = useState<string | null>(null);
    const [noteDate, setNoteDate] = useState<string | null>(null);
    const grid = useRef<HTMLTableElement>(null);

    const cellAt = (position: Position): Cell | undefined => {
        const day = sheet.days[position.row];
        const task = sheet.tasks[position.col];

        return day && task ? sheet.cells[day.date]?.[task.id] : undefined;
    };

    function focusCell(position: Position) {
        const row = Math.max(0, Math.min(sheet.days.length - 1, position.row));
        const col = Math.max(0, Math.min(sheet.tasks.length - 1, position.col));
        setSelected({ row, col });
        // After the edit input unmounts, the cell button is back in the DOM.
        requestAnimationFrame(() =>
            grid.current
                ?.querySelector<HTMLElement>(`[data-cell="${row}:${col}"]`)
                ?.focus(),
        );
    }

    function startEditing(position: Position, initial?: string) {
        const cell = cellAt(position);
        const task = sheet.tasks[position.col];

        if ((cell?.entries.length ?? 0) > 1) {
            toast.info(
                'Esta célula tem vários lançamentos; edite-os nos detalhes abaixo.',
            );

            return;
        }

        if (task.status === 'archived' && !cell) {
            toast.info('Tarefas arquivadas não recebem novos lançamentos.');

            return;
        }

        handled.current = false;
        setEditing({
            position,
            text: initial ?? (cell ? formatClock(cell.minutes) : ''),
        });
    }

    function commit(move?: Position) {
        if (!editing) {
            return;
        }

        const { position, text } = editing;
        const minutes = parseDuration(text);
        const day = sheet.days[position.row];
        const task = sheet.tasks[position.col];
        const current = cellAt(position)?.minutes ?? 0;

        if (minutes === null || minutes > 1440) {
            toast.error(
                'Duração inválida. Use 1:30, 1h30, 45m ou 1,5h (até 24h).',
            );
            handled.current = false;

            return;
        }

        setEditing(null);

        if (minutes !== current) {
            const key = `${position.row}:${position.col}`;
            router.put(
                time.sheet.cell.url(),
                { task_id: task.id, entry_date: day.date, minutes },
                {
                    preserveScroll: true,
                    preserveState: true,
                    only: ['sheet'],
                    onStart: () => setSaving(key),
                    onFinish: () => setSaving(null),
                    onError: (errors) =>
                        toast.error(
                            errors.minutes ??
                                errors.task_id ??
                                'Não foi possível salvar.',
                        ),
                },
            );
        }

        focusCell(move ?? position);
    }

    function onCellKeyDown(event: KeyboardEvent, position: Position) {
        const moves: Record<string, Position> = {
            ArrowUp: { row: position.row - 1, col: position.col },
            ArrowDown: { row: position.row + 1, col: position.col },
            ArrowLeft: { row: position.row, col: position.col - 1 },
            ArrowRight: { row: position.row, col: position.col + 1 },
        };

        if (moves[event.key]) {
            event.preventDefault();
            focusCell(moves[event.key]);
        } else if (event.key === 'Enter' || event.key === 'F2') {
            event.preventDefault();
            startEditing(position);
        } else if (/^[0-9]$/.test(event.key)) {
            event.preventDefault();
            startEditing(position, event.key);
        } else if (event.key === 'Delete' || event.key === 'Backspace') {
            if ((cellAt(position)?.entries.length ?? 0) === 1) {
                event.preventDefault();
                startEditing(position, '');
            }
        }
    }

    const title =
        filters.view === 'month'
            ? capitalize(formatMonth(period.from.slice(0, 7)))
            : `Semana de ${formatShortDate(period.from)} a ${formatDate(period.to)}`;
    const selectedDay = selected ? sheet.days[selected.row] : null;
    const selectedTask = selected ? sheet.tasks[selected.col] : null;
    const selectedCell = selected ? cellAt(selected) : undefined;

    return (
        <>
            <Head title="Planilha de tempo" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title={title}
                    description="Tempo lançado manualmente por dia e tarefa. Clique numa célula para ver os lançamentos; Enter, duplo clique ou um número para editar."
                >
                    <ViewSwitch date={filters.date} current={filters.view} />
                    <div className="flex items-center gap-1">
                        <Button variant="outline" size="icon" asChild>
                            <Link
                                href={time.sheet({
                                    query: {
                                        ...query(filters),
                                        date: period.previous,
                                    },
                                })}
                                aria-label="Período anterior"
                                preserveScroll
                            >
                                <ChevronLeft />
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link
                                href={time.sheet({
                                    query: { ...query(filters), date: today() },
                                })}
                            >
                                Hoje
                            </Link>
                        </Button>
                        <Button variant="outline" size="icon" asChild>
                            <Link
                                href={time.sheet({
                                    query: {
                                        ...query(filters),
                                        date: period.next,
                                    },
                                })}
                                aria-label="Próximo período"
                                preserveScroll
                            >
                                <ChevronRight />
                            </Link>
                        </Button>
                    </div>
                    <EntryFormDialog
                        tasks={task_options}
                        draft={{ date: filters.date }}
                        trigger={
                            <Button>
                                <Plus />
                                Lançar tempo
                            </Button>
                        }
                    />
                </PageHeader>

                <div className="flex flex-wrap items-end gap-3">
                    <FilterSelect
                        id="sheet-task"
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
                    <FilterSelect
                        id="sheet-sort"
                        label="Ordenar colunas"
                        value={filters.sort}
                        options={[
                            ['title', 'Por nome'],
                            ['total', 'Por tempo no período'],
                        ]}
                        onChange={(value) =>
                            filter({ sort: value as Props['filters']['sort'] })
                        }
                    />
                </div>

                {sheet.tasks.length === 0 ? (
                    <EmptyState
                        icon={ListTodo}
                        title="Nenhuma tarefa para mostrar"
                        description="A planilha mostra as tarefas ativas e as que têm tempo lançado no período."
                    >
                        <Button asChild>
                            <Link href={time.tasks.index()}>Criar tarefa</Link>
                        </Button>
                    </EmptyState>
                ) : (
                    <div className="max-h-[70vh] overflow-auto rounded-xl border">
                        <table
                            ref={grid}
                            className="w-full border-separate border-spacing-0 text-sm"
                            role="grid"
                            aria-label={`Planilha de tempo: ${title}`}
                        >
                            <thead>
                                <tr>
                                    <th
                                        scope="col"
                                        className="sticky top-0 left-0 z-30 border-b bg-muted px-3 py-2 text-left font-medium"
                                    >
                                        Data
                                    </th>
                                    {sheet.tasks.map((task) => (
                                        <th
                                            key={task.id}
                                            scope="col"
                                            className="sticky top-0 z-20 min-w-28 border-b bg-muted px-3 py-2 text-right font-medium"
                                        >
                                            <span
                                                className={cn(
                                                    'line-clamp-2',
                                                    task.status !== 'active' &&
                                                        'text-muted-foreground',
                                                )}
                                                title={task.title}
                                            >
                                                {task.title}
                                            </span>
                                            {task.status !== 'active' && (
                                                <span className="block text-xs font-normal text-muted-foreground">
                                                    {task.status === 'archived'
                                                        ? 'arquivada'
                                                        : 'concluída'}
                                                </span>
                                            )}
                                        </th>
                                    ))}
                                    <th
                                        scope="col"
                                        className="sticky top-0 z-20 min-w-24 border-b bg-muted px-3 py-2 text-right font-semibold"
                                    >
                                        Total do dia
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {sheet.days.map((day, row) => (
                                    <tr
                                        key={day.date}
                                        className={cn(
                                            day.is_today && 'bg-primary/5',
                                        )}
                                    >
                                        <th
                                            scope="row"
                                            className={cn(
                                                'sticky left-0 z-10 border-b px-3 py-1.5 text-left font-normal whitespace-nowrap',
                                                day.is_today
                                                    ? 'bg-accent'
                                                    : 'bg-background',
                                            )}
                                        >
                                            <div className="flex items-center gap-2">
                                                <Link
                                                    href={time.days.show(
                                                        day.date,
                                                    )}
                                                    className="hover:underline"
                                                    aria-label={`Abrir ${weekday(day.date)}, ${formatDate(day.date)}`}
                                                >
                                                    <span className="inline-block w-8 text-muted-foreground">
                                                        {shortWeekday(day.date)}
                                                    </span>
                                                    {formatShortDate(day.date)}
                                                </Link>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setNoteDate(day.date)
                                                    }
                                                    className={cn(
                                                        'rounded p-1 hover:bg-muted',
                                                        day.note
                                                            ? 'text-primary'
                                                            : 'text-muted-foreground/40 hover:text-muted-foreground',
                                                    )}
                                                    title={
                                                        day.note ??
                                                        'Escrever nota do dia'
                                                    }
                                                    aria-label={
                                                        day.note
                                                            ? `Nota de ${formatDate(day.date)}: ${day.note}`
                                                            : `Escrever nota de ${formatDate(day.date)}`
                                                    }
                                                >
                                                    <NotebookPen className="size-3.5" />
                                                </button>
                                            </div>
                                        </th>
                                        {sheet.tasks.map((task, col) => {
                                            const cell =
                                                sheet.cells[day.date]?.[
                                                    task.id
                                                ];
                                            const isEditing =
                                                editing?.position.row === row &&
                                                editing.position.col === col;
                                            const isSelected =
                                                selected?.row === row &&
                                                selected.col === col;

                                            return (
                                                <td
                                                    key={task.id}
                                                    className="border-b p-0 text-right"
                                                >
                                                    {isEditing ? (
                                                        <input
                                                            autoFocus
                                                            aria-label={`Tempo de ${task.title} em ${formatDate(day.date)}`}
                                                            className="h-9 w-full bg-background px-3 text-right tabular-nums outline-2 outline-primary"
                                                            value={editing.text}
                                                            onChange={(event) =>
                                                                setEditing({
                                                                    position:
                                                                        editing.position,
                                                                    text: event
                                                                        .target
                                                                        .value,
                                                                })
                                                            }
                                                            onBlur={() => {
                                                                if (
                                                                    !handled.current
                                                                ) {
                                                                    handled.current = true;
                                                                    commit();
                                                                }
                                                            }}
                                                            onKeyDown={(
                                                                event,
                                                            ) => {
                                                                if (
                                                                    event.key ===
                                                                    'Enter'
                                                                ) {
                                                                    event.preventDefault();
                                                                    handled.current = true;
                                                                    commit({
                                                                        row:
                                                                            row +
                                                                            1,
                                                                        col,
                                                                    });
                                                                } else if (
                                                                    event.key ===
                                                                    'Tab'
                                                                ) {
                                                                    event.preventDefault();
                                                                    handled.current = true;
                                                                    commit({
                                                                        row,
                                                                        col:
                                                                            col +
                                                                            (event.shiftKey
                                                                                ? -1
                                                                                : 1),
                                                                    });
                                                                } else if (
                                                                    event.key ===
                                                                    'Escape'
                                                                ) {
                                                                    handled.current = true;
                                                                    setEditing(
                                                                        null,
                                                                    );
                                                                    focusCell({
                                                                        row,
                                                                        col,
                                                                    });
                                                                }
                                                            }}
                                                        />
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            data-cell={`${row}:${col}`}
                                                            tabIndex={
                                                                isSelected ||
                                                                (!selected &&
                                                                    row === 0 &&
                                                                    col === 0)
                                                                    ? 0
                                                                    : -1
                                                            }
                                                            onClick={() =>
                                                                setSelected({
                                                                    row,
                                                                    col,
                                                                })
                                                            }
                                                            onDoubleClick={() =>
                                                                startEditing({
                                                                    row,
                                                                    col,
                                                                })
                                                            }
                                                            onFocus={() =>
                                                                setSelected({
                                                                    row,
                                                                    col,
                                                                })
                                                            }
                                                            onKeyDown={(
                                                                event,
                                                            ) =>
                                                                onCellKeyDown(
                                                                    event,
                                                                    {
                                                                        row,
                                                                        col,
                                                                    },
                                                                )
                                                            }
                                                            aria-label={`${task.title}, ${formatDate(day.date)}: ${cell ? formatMinutes(cell.minutes) : 'vazio'}`}
                                                            className={cn(
                                                                'flex h-9 w-full items-center justify-end gap-1 px-3 tabular-nums outline-none hover:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
                                                                isSelected &&
                                                                    'bg-primary/10',
                                                                !cell &&
                                                                    'text-muted-foreground/40',
                                                            )}
                                                        >
                                                            {saving ===
                                                                `${row}:${col}` && (
                                                                <Spinner className="size-3" />
                                                            )}
                                                            {cell
                                                                ? formatMinutes(
                                                                      cell.minutes,
                                                                  )
                                                                : '·'}
                                                            {cell &&
                                                                cell.entries
                                                                    .length >
                                                                    1 && (
                                                                    <span className="text-[10px] text-muted-foreground">
                                                                        ×
                                                                        {
                                                                            cell
                                                                                .entries
                                                                                .length
                                                                        }
                                                                    </span>
                                                                )}
                                                        </button>
                                                    )}
                                                </td>
                                            );
                                        })}
                                        <td className="border-b px-3 py-1.5 text-right font-medium tabular-nums">
                                            {day.total_minutes > 0
                                                ? formatMinutes(
                                                      day.total_minutes,
                                                  )
                                                : '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th
                                        scope="row"
                                        className="sticky bottom-0 left-0 z-30 border-t bg-muted px-3 py-2 text-left font-semibold"
                                    >
                                        Total
                                    </th>
                                    {sheet.tasks.map((task) => (
                                        <td
                                            key={task.id}
                                            className="sticky bottom-0 z-20 border-t bg-muted px-3 py-2 text-right font-semibold tabular-nums"
                                        >
                                            {formatMinutes(task.total_minutes)}
                                        </td>
                                    ))}
                                    <td className="sticky bottom-0 z-20 border-t bg-muted px-3 py-2 text-right font-semibold tabular-nums">
                                        {formatMinutes(sheet.total_minutes)}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                )}

                {selectedDay && selectedTask && (
                    <Card>
                        <CardHeader className="flex-row flex-wrap items-start justify-between gap-2">
                            <div className="space-y-1.5">
                                <CardTitle>
                                    {selectedTask.title} ·{' '}
                                    {capitalize(weekday(selectedDay.date))},{' '}
                                    {formatDate(selectedDay.date)}
                                </CardTitle>
                                <CardDescription>
                                    {selectedCell
                                        ? `${selectedCell.entries.length} lançamento(s), ${formatMinutes(selectedCell.minutes)} no total.`
                                        : 'Nenhum lançamento nesta célula.'}
                                </CardDescription>
                            </div>
                            {selectedTask.status !== 'archived' && (
                                <EntryFormDialog
                                    tasks={task_options}
                                    draft={{
                                        task_id: selectedTask.id,
                                        date: selectedDay.date,
                                    }}
                                    trigger={
                                        <Button size="sm" variant="outline">
                                            <Plus />
                                            Adicionar lançamento
                                        </Button>
                                    }
                                />
                            )}
                        </CardHeader>
                        {selectedCell && (
                            <CardContent>
                                <ul className="divide-y text-sm">
                                    {selectedCell.entries.map((entry) => (
                                        <li
                                            key={entry.id}
                                            className="flex items-center justify-between gap-2 py-2"
                                        >
                                            <span className="min-w-0 truncate text-muted-foreground">
                                                {entry.description ??
                                                    'Sem descrição'}
                                            </span>
                                            <span className="flex items-center gap-1">
                                                <span className="font-medium tabular-nums">
                                                    {formatMinutes(
                                                        entry.minutes,
                                                    )}
                                                </span>
                                                <EntryFormDialog
                                                    tasks={task_options}
                                                    entry={entry}
                                                    trigger={
                                                        <Button
                                                            size="icon"
                                                            variant="ghost"
                                                            className="size-8"
                                                            aria-label="Editar lançamento"
                                                        >
                                                            <Pencil className="size-4" />
                                                        </Button>
                                                    }
                                                />
                                                <ConfirmDialog
                                                    url={time.entries.destroy.url(
                                                        entry.id,
                                                    )}
                                                    title="Excluir lançamento?"
                                                    description={`${formatMinutes(entry.minutes)} de “${selectedTask.title}” em ${formatDate(entry.date)} serão excluídos.`}
                                                    confirmLabel="Excluir"
                                                    trigger={
                                                        <Button
                                                            size="icon"
                                                            variant="ghost"
                                                            className="size-8 text-destructive"
                                                            aria-label="Excluir lançamento"
                                                        >
                                                            <Trash2 className="size-4" />
                                                        </Button>
                                                    }
                                                />
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </CardContent>
                        )}
                    </Card>
                )}
            </div>

            <Sheet
                open={noteDate !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setNoteDate(null);
                        router.reload({ only: ['sheet'] });
                    }
                }}
            >
                <SheetContent className="w-full overflow-y-auto sm:max-w-2xl">
                    {noteDate && (
                        <>
                            <SheetHeader>
                                <SheetTitle>
                                    Nota de {weekday(noteDate)},{' '}
                                    {formatDate(noteDate)}
                                </SheetTitle>
                                <SheetDescription>
                                    <Link
                                        href={time.days.show(noteDate)}
                                        className="underline"
                                    >
                                        Abrir o dia completo
                                    </Link>
                                </SheetDescription>
                            </SheetHeader>
                            <div className="px-4 pb-4">
                                <NoteLoader key={noteDate} date={noteDate} />
                            </div>
                        </>
                    )}
                </SheetContent>
            </Sheet>
        </>
    );
}

function query(filters: Props['filters']) {
    return {
        view: filters.view,
        ...(filters.task ? { task: filters.task } : {}),
        ...(filters.sort === 'total' ? { sort: 'total' } : {}),
    };
}

/**
 * Loads the full note of a day on demand (the sheet only carries previews).
 */
function NoteLoader({ date }: { date: string }) {
    const [note, setNote] = useState<DailyNote | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        const controller = new AbortController();

        jsonRequest<DailyNote>(
            'GET',
            time.notes.show.url(date),
            undefined,
            controller.signal,
        )
            .then(setNote)
            .catch((error: unknown) => {
                if (
                    !(
                        error instanceof DOMException &&
                        error.name === 'AbortError'
                    )
                ) {
                    setFailed(true);
                }
            });

        return () => controller.abort();
    }, [date]);

    if (failed) {
        return (
            <p className="text-sm text-destructive">
                Não foi possível carregar a nota. Tente novamente.
            </p>
        );
    }

    return note ? (
        <NoteEditor note={note} />
    ) : (
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <Spinner /> Carregando…
        </div>
    );
}

TimeSheet.layout = {
    breadcrumbs: [
        { title: 'Tarefas e tempo', href: time.index() },
        { title: 'Planilha', href: time.sheet() },
    ],
};
