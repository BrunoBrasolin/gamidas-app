import { Head, router, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    List,
    Pencil,
    Plus,
    Printer,
    Repeat,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { CSSProperties } from 'react';
import { useState } from 'react';
import { EventChip, EventIcon } from '@/components/calendar/event-badge';
import { EventFormDialog } from '@/components/calendar/event-form-dialog';
import type { EventEditor } from '@/components/calendar/event-form-dialog';
import { EmptyState } from '@/components/finance/empty-state';
import { MonthNavigator } from '@/components/finance/month-navigator';
import { PageHeader } from '@/components/finance/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useIsMobile } from '@/hooks/use-mobile';
import {
    describeRecurrence,
    EVENT_COLORS,
    eventKey,
    formatLongDate,
    groupByDate,
    monthWeeks,
    WEEKDAYS,
    weekdayName,
} from '@/lib/calendar';
import { capitalize, formatDate, formatMonth, today } from '@/lib/format';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import calendar from '@/routes/calendar';
import type { CalendarEvent } from '@/types';

type View = 'calendar' | 'list';

type Props = {
    filters: { month: string; view: View };
    /** Events of every day shown in the month grid, adjacent days included. */
    events: CalendarEvent[];
};

/** Events listed in a calendar day before collapsing into "+N mais". */
const VISIBLE_PER_DAY = 3;

const VIEWS: [View, string, LucideIcon][] = [
    ['calendar', 'Calendário', CalendarDays],
    ['list', 'Lista', List],
];

export default function CalendarIndex({ filters, events }: Props) {
    const { household } = usePage().props;
    const isMobile = useIsMobile();
    const now = today();
    const currentMonth = now.slice(0, 7);
    const byDate = groupByDate(events);
    const monthEvents = events.filter((event) =>
        event.date.startsWith(filters.month),
    );

    const [editor, setEditor] = useState<EventEditor>({
        open: false,
        key: 0,
        date: now,
    });
    const [selectedDay, setSelectedDay] = useState<string | null>(null);

    const openEditor = (date: string, event?: CalendarEvent) => {
        setSelectedDay(null);
        setEditor((current) => ({
            open: true,
            key: current.key + 1,
            date,
            event,
        }));
    };

    const addEvent = () =>
        openEditor(
            filters.month === currentMonth ? now : `${filters.month}-01`,
        );

    // On phones the grid only shows dots, so a day with events opens its
    // agenda first; everywhere else clicking a day starts a new event there.
    const onDayClick = (date: string) =>
        isMobile && byDate.has(date) ? setSelectedDay(date) : openEditor(date);

    const visit = (changes: Partial<Props['filters']>) => {
        const next = { ...filters, ...changes };

        router.get(
            calendar.index.url(),
            {
                month: next.month,
                view: next.view === 'list' ? 'list' : undefined,
            },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title="Agenda" />
            <style>
                {'@media print { @page { size: landscape; margin: 10mm; } }'}
            </style>
            <div className="flex flex-1 flex-col gap-4 p-4 print:gap-3 print:p-0">
                <div className="print:hidden">
                    <PageHeader
                        title="Agenda"
                        description="Eventos e compromissos da casa, visíveis para todos os membros."
                    >
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => window.print()}
                        >
                            <Printer />
                            Imprimir
                        </Button>
                        <Button type="button" onClick={addEvent}>
                            <Plus />
                            Adicionar evento
                        </Button>
                    </PageHeader>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-2 print:hidden">
                    <div className="flex flex-wrap items-center gap-2">
                        <MonthNavigator
                            month={filters.month}
                            onChange={(month) => visit({ month })}
                        />
                        <Button
                            type="button"
                            variant="outline"
                            disabled={filters.month === currentMonth}
                            onClick={() => visit({ month: currentMonth })}
                        >
                            Hoje
                        </Button>
                    </div>
                    <div
                        className="inline-flex rounded-lg bg-muted p-1 text-sm"
                        role="group"
                        aria-label="Modo de visualização"
                    >
                        {VIEWS.map(([value, label, Icon]) => (
                            <button
                                key={value}
                                type="button"
                                aria-pressed={filters.view === value}
                                onClick={() => visit({ view: value })}
                                className={cn(
                                    'flex items-center gap-1.5 rounded-md px-3 py-1.5 font-medium',
                                    filters.view === value
                                        ? 'bg-background shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                <Icon className="size-4" aria-hidden />
                                {label}
                            </button>
                        ))}
                    </div>
                </div>

                <header className="hidden items-end justify-between border-b border-neutral-300 pb-2 print:flex">
                    <div>
                        {household && (
                            <p className="text-[9pt] tracking-wide text-neutral-500 uppercase">
                                {household.name}
                            </p>
                        )}
                        <h1 className="text-[18pt] leading-tight font-semibold">
                            Agenda · {capitalize(formatMonth(filters.month))}
                        </h1>
                    </div>
                    <p className="text-[8pt] text-neutral-500">
                        {monthEvents.length} evento(s) · impresso em{' '}
                        {formatDate(now)}
                    </p>
                </header>

                {filters.view === 'calendar' ? (
                    <MonthGrid
                        month={filters.month}
                        today={now}
                        byDate={byDate}
                        onDayClick={onDayClick}
                        onShowDay={setSelectedDay}
                        onEventClick={(event) => openEditor(event.date, event)}
                    />
                ) : (
                    <EventList
                        events={monthEvents}
                        today={now}
                        onAdd={addEvent}
                        onEventClick={(event) => openEditor(event.date, event)}
                    />
                )}
            </div>

            <DayDialog
                date={selectedDay}
                events={selectedDay ? (byDate.get(selectedDay) ?? []) : []}
                onClose={() => setSelectedDay(null)}
                onAdd={openEditor}
                onEventClick={(event) => openEditor(event.date, event)}
            />

            <EventFormDialog
                editor={editor}
                onOpenChange={(open) =>
                    setEditor((current) => ({ ...current, open }))
                }
            />
        </>
    );
}

function MonthGrid({
    month,
    today,
    byDate,
    onDayClick,
    onShowDay,
    onEventClick,
}: {
    month: string;
    today: string;
    byDate: Map<string, CalendarEvent[]>;
    onDayClick: (date: string) => void;
    onShowDay: (date: string) => void;
    onEventClick: (event: CalendarEvent) => void;
}) {
    const weeks = monthWeeks(month);

    return (
        <div className="overflow-hidden rounded-xl border bg-border print:rounded-none print:border-neutral-400 print:bg-neutral-300">
            <div className="grid grid-cols-7 gap-px">
                {WEEKDAYS.map((weekday) => (
                    <div
                        key={weekday}
                        className="bg-muted px-2 py-2 text-center text-xs font-medium text-muted-foreground md:text-left print:bg-neutral-100 print:py-1 print:text-left print:text-[8pt] print:text-neutral-700 print:uppercase"
                    >
                        {weekday}
                    </div>
                ))}
            </div>
            <div
                className="grid auto-rows-fr grid-cols-7 gap-px border-t print:h-[155mm] print:grid-rows-[repeat(var(--weeks),minmax(0,1fr))] print:border-neutral-400"
                style={{ '--weeks': weeks.length } as CSSProperties}
            >
                {weeks.flat().map((date) => {
                    const dayEvents = byDate.get(date) ?? [];
                    const outside = !date.startsWith(month);
                    const isToday = date === today;
                    const hidden = dayEvents.length - VISIBLE_PER_DAY;

                    return (
                        <div
                            key={date}
                            className={cn(
                                'group relative flex min-h-16 min-w-0 flex-col gap-1 bg-card p-1 md:min-h-28 md:p-1.5 print:min-h-0 print:overflow-hidden print:bg-white',
                                outside &&
                                    'bg-muted/60 dark:bg-card print:bg-neutral-50',
                            )}
                        >
                            <button
                                type="button"
                                onClick={() => onDayClick(date)}
                                aria-label={`${capitalize(formatLongDate(date))}: ${dayEvents.length > 0 ? `${dayEvents.length} evento(s)` : 'sem eventos'}`}
                                className="absolute inset-0 cursor-pointer transition-colors outline-none hover:bg-accent/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset print:hidden"
                            />
                            <div className="pointer-events-none relative flex items-center justify-between">
                                <span
                                    className={cn(
                                        'flex size-6 items-center justify-center rounded-full text-xs font-medium tabular-nums print:size-auto print:text-[9pt]',
                                        outside && 'text-muted-foreground',
                                        isToday &&
                                            'bg-primary text-primary-foreground print:bg-transparent print:font-bold print:text-black print:underline',
                                    )}
                                >
                                    {Number(date.slice(8))}
                                </span>
                                <Plus
                                    className="hidden size-3.5 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 md:block print:hidden"
                                    aria-hidden
                                />
                            </div>

                            <div
                                className={cn(
                                    'relative hidden min-w-0 flex-col gap-0.5 md:flex print:flex',
                                    outside && 'opacity-60',
                                )}
                            >
                                {dayEvents
                                    .slice(0, VISIBLE_PER_DAY)
                                    .map((event) => (
                                        <EventChip
                                            key={eventKey(event)}
                                            event={event}
                                            onClick={() => onEventClick(event)}
                                            className="print:text-[7.5pt]"
                                        />
                                    ))}
                                {hidden > 0 && (
                                    <button
                                        type="button"
                                        onClick={() => onShowDay(date)}
                                        className="w-fit rounded-sm px-1 text-left text-xs font-medium text-muted-foreground hover:text-foreground print:text-[7.5pt]"
                                    >
                                        +{hidden} mais
                                    </button>
                                )}
                            </div>

                            <div
                                className="pointer-events-none relative flex flex-wrap gap-0.5 px-0.5 md:hidden print:hidden"
                                aria-hidden
                            >
                                {dayEvents.slice(0, 6).map((event) => (
                                    <span
                                        key={eventKey(event)}
                                        className={cn(
                                            'size-1.5 rounded-full',
                                            EVENT_COLORS[event.color].swatch,
                                        )}
                                    />
                                ))}
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

function EventList({
    events,
    today,
    onAdd,
    onEventClick,
}: {
    events: CalendarEvent[];
    today: string;
    onAdd: () => void;
    onEventClick: (event: CalendarEvent) => void;
}) {
    if (events.length === 0) {
        return (
            <EmptyState
                icon={CalendarDays}
                title="Nenhum evento neste mês"
                description="Adicione aniversários, consultas, viagens e outros compromissos da casa."
            >
                <Button type="button" onClick={onAdd} className="print:hidden">
                    <Plus />
                    Adicionar evento
                </Button>
            </EmptyState>
        );
    }

    return (
        <div className="space-y-4 print:space-y-2">
            {[...groupByDate(events)].map(([date, items]) => (
                <section
                    key={date}
                    aria-label={capitalize(formatLongDate(date))}
                    className="grid break-inside-avoid gap-2 md:grid-cols-[9rem_1fr] md:gap-4 print:grid-cols-[7rem_1fr] print:gap-3"
                >
                    <div className="flex items-baseline gap-2 md:flex-col md:gap-0 md:pt-2 print:pt-1">
                        <span className="text-2xl font-semibold tabular-nums print:text-[14pt]">
                            {Number(date.slice(8))}
                        </span>
                        <span className="text-sm text-muted-foreground print:text-[8pt]">
                            {weekdayName(date)}
                        </span>
                        {date === today && (
                            <Badge className="md:mt-1 print:hidden">Hoje</Badge>
                        )}
                    </div>
                    <ul className="divide-y overflow-hidden rounded-xl border bg-card print:rounded-md print:border-neutral-300">
                        {items.map((event) => (
                            <li key={eventKey(event)}>
                                <button
                                    type="button"
                                    onClick={() => onEventClick(event)}
                                    className="group flex w-full items-start gap-3 p-3 text-left transition-colors outline-none hover:bg-accent/50 focus-visible:bg-accent/50 print:p-2"
                                >
                                    <EventIcon
                                        event={event}
                                        className="print:size-7"
                                    />
                                    <EventSummary event={event} />
                                    <Pencil
                                        className="mt-1 size-4 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100 print:hidden"
                                        aria-hidden
                                    />
                                </button>
                            </li>
                        ))}
                    </ul>
                </section>
            ))}
        </div>
    );
}

function EventSummary({ event }: { event: CalendarEvent }) {
    return (
        <div className="min-w-0 flex-1 space-y-1">
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span className="font-medium print:text-[10pt]">
                    {event.title}
                </span>
                <span
                    className={cn(
                        'rounded-sm border-l-[3px] px-1.5 py-0.5 text-xs font-medium tabular-nums print:text-[7.5pt]',
                        EVENT_COLORS[event.color].badge,
                    )}
                >
                    {event.time ?? 'Dia todo'}
                </span>
            </div>
            {event.recurrence && event.series && (
                <p className="flex items-center gap-1.5 text-xs text-muted-foreground print:text-[7.5pt]">
                    <Repeat className="size-3 shrink-0" aria-hidden />
                    {describeRecurrence(event.recurrence, event.series.date)}
                    {event.modified && ' · alterado nesta data'}
                </p>
            )}
            {event.description && (
                <p className="text-sm whitespace-pre-line text-muted-foreground print:text-[8.5pt]">
                    {event.description}
                </p>
            )}
        </div>
    );
}

function DayDialog({
    date,
    events,
    onClose,
    onAdd,
    onEventClick,
}: {
    date: string | null;
    events: CalendarEvent[];
    onClose: () => void;
    onAdd: (date: string) => void;
    onEventClick: (event: CalendarEvent) => void;
}) {
    return (
        <Dialog
            open={date !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="max-h-[calc(100svh-2rem)] overflow-y-auto sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {date && capitalize(formatLongDate(date))}
                    </DialogTitle>
                    <DialogDescription>
                        {events.length} evento(s) neste dia
                    </DialogDescription>
                </DialogHeader>
                <ul className="-mx-2 divide-y">
                    {events.map((event) => (
                        <li key={eventKey(event)}>
                            <button
                                type="button"
                                onClick={() => onEventClick(event)}
                                className="flex w-full items-start gap-3 rounded-md p-2 text-left transition-colors hover:bg-accent/50"
                            >
                                <EventIcon event={event} />
                                <EventSummary event={event} />
                            </button>
                        </li>
                    ))}
                </ul>
                <DialogFooter>
                    <Button type="button" onClick={() => date && onAdd(date)}>
                        <Plus />
                        Adicionar evento
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

CalendarIndex.layout = {
    breadcrumbs: [
        {
            title: 'Painel',
            href: dashboard(),
        },
        {
            title: 'Agenda',
            href: calendar.index(),
        },
    ],
};
