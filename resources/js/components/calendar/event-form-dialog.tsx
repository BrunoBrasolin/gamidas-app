import { Form } from '@inertiajs/react';
import { Repeat, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/finance/confirm-dialog';
import { FormField } from '@/components/finance/form-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import {
    describeRecurrence,
    EVENT_COLORS,
    EVENT_ICONS,
    RECURRENCE_UNITS,
    WEEKDAYS,
    weekdayOf,
} from '@/lib/calendar';
import { formatDate, formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import calendar from '@/routes/calendar';
import type {
    CalendarEvent,
    EventColor,
    EventIcon,
    RecurrenceFrequency,
} from '@/types';

export type EventEditor = {
    open: boolean;
    /** Changes on every opening so the form starts from fresh values. */
    key: number;
    /** Default date for a new event, "YYYY-MM-DD". */
    date: string;
    event?: CalendarEvent;
};

/** Which part of a recurring event the form edits or deletes. */
type Scope = 'occurrence' | 'series';

/** Choices of the "Repetir" select; "custom" sets interval and weekdays. */
type Preset = 'none' | RecurrenceFrequency | 'custom';

const PRESETS: [Preset, string][] = [
    ['none', 'Não se repete'],
    ...(
        Object.entries(RECURRENCE_UNITS) as [
            RecurrenceFrequency,
            (typeof RECURRENCE_UNITS)[RecurrenceFrequency],
        ][]
    ).map(([value, unit]): [Preset, string] => [value, unit.label]),
    ['custom', 'Personalizado…'],
];

const SCOPES: [Scope, string][] = [
    ['occurrence', 'Somente este evento'],
    ['series', 'Toda a série'],
];

/**
 * Creates a new event or edits (and deletes) an existing one. For an
 * occurrence of a recurring event, the user chooses whether the changes
 * apply to that occurrence alone or to the whole series.
 */
export function EventFormDialog({
    editor,
    onOpenChange,
}: {
    editor: EventEditor;
    onOpenChange: (open: boolean) => void;
}) {
    const { event } = editor;

    return (
        <Dialog open={editor.open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[calc(100svh-2rem)] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {event ? 'Editar evento' : 'Adicionar evento'}
                    </DialogTitle>
                    <DialogDescription>
                        {event
                            ? `${formatDate(event.date)}${event.created_by ? ` · criado por ${event.created_by}` : ''}${event.created_at ? ` em ${formatDateTime(event.created_at)}` : ''}`
                            : 'O evento fica visível para todos da casa.'}
                    </DialogDescription>
                    {event?.recurrence && event.series && (
                        <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
                            <Repeat className="size-3.5" aria-hidden />
                            {describeRecurrence(
                                event.recurrence,
                                event.series.date,
                            )}
                        </p>
                    )}
                </DialogHeader>
                <EventEditorBody
                    key={editor.key}
                    editor={editor}
                    onDone={() => onOpenChange(false)}
                />
            </DialogContent>
        </Dialog>
    );
}

function EventEditorBody({
    editor,
    onDone,
}: {
    editor: EventEditor;
    onDone: () => void;
}) {
    const [scope, setScope] = useState<Scope>('occurrence');
    const recurring = Boolean(editor.event?.recurrence);

    return (
        <div className="space-y-4">
            {recurring && (
                <div
                    className="grid grid-cols-2 rounded-lg bg-muted p-1 text-sm"
                    role="group"
                    aria-label="Aplicar alterações a"
                >
                    {SCOPES.map(([value, label]) => (
                        <button
                            key={value}
                            type="button"
                            aria-pressed={scope === value}
                            onClick={() => setScope(value)}
                            className={cn(
                                'rounded-md px-3 py-1.5 font-medium',
                                scope === value
                                    ? 'bg-background shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {label}
                        </button>
                    ))}
                </div>
            )}
            <EventForm
                // Each scope starts from its own values.
                key={scope}
                editor={editor}
                scope={recurring ? scope : null}
                onDone={onDone}
            />
        </div>
    );
}

/**
 * The preset matching an existing rule, "custom" for anything the simple
 * presets can't express.
 */
function presetOf(event: CalendarEvent | undefined, start: string): Preset {
    const recurrence = event?.recurrence;

    if (!recurrence) {
        return 'none';
    }

    const simpleWeekdays =
        recurrence.frequency !== 'weekly' ||
        (recurrence.weekdays.length === 1 &&
            recurrence.weekdays[0] === weekdayOf(start));

    return recurrence.interval === 1 && simpleWeekdays
        ? recurrence.frequency
        : 'custom';
}

function EventForm({
    editor: { event, date },
    scope,
    onDone,
}: {
    editor: EventEditor;
    /** Null for new and single events. */
    scope: Scope | null;
    onDone: () => void;
}) {
    // A series is edited from its own values, an occurrence from its own.
    const values = scope === 'series' ? event?.series : event;
    const withRecurrence = scope !== 'occurrence';
    const [color, setColor] = useState<EventColor>(values?.color ?? 'blue');
    const [icon, setIcon] = useState<EventIcon>(values?.icon ?? 'calendar');
    const [startDate, setStartDate] = useState(values?.date ?? date);
    const form = event
        ? calendar.events.update.form(event.id)
        : calendar.events.store.form();
    const deleteUrl = event
        ? calendar.events.destroy.url(
              event.id,
              scope
                  ? {
                        query: {
                            scope,
                            occurrence:
                                scope === 'occurrence'
                                    ? (event.occurrence ?? undefined)
                                    : undefined,
                        },
                    }
                  : undefined,
          )
        : null;

    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-4"
        >
            {({ errors, processing }) => (
                <>
                    {scope && (
                        <>
                            <input type="hidden" name="scope" value={scope} />
                            {scope === 'occurrence' && event?.occurrence && (
                                <input
                                    type="hidden"
                                    name="occurrence"
                                    value={event.occurrence}
                                />
                            )}
                        </>
                    )}
                    <InputError message={errors.occurrence} />
                    <FormField
                        id="event-title"
                        label="Título"
                        error={errors.title}
                    >
                        <Input
                            id="event-title"
                            name="title"
                            required
                            maxLength={120}
                            placeholder="Ex.: Aniversário da Letícia"
                            defaultValue={values?.title}
                        />
                    </FormField>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField
                            id="event-date"
                            label={scope === 'series' ? 'Início' : 'Data'}
                            error={errors.event_date}
                        >
                            <Input
                                id="event-date"
                                name="event_date"
                                type="date"
                                required
                                min="2000-01-01"
                                max="2100-12-31"
                                value={startDate}
                                onChange={(change) =>
                                    setStartDate(change.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="event-time"
                            label="Horário (opcional)"
                            error={errors.event_time}
                            hint="Em branco para o dia todo."
                        >
                            <Input
                                id="event-time"
                                name="event_time"
                                type="time"
                                defaultValue={values?.time ?? ''}
                            />
                        </FormField>
                    </div>

                    {withRecurrence && (
                        <RecurrenceFields
                            event={scope === 'series' ? event : undefined}
                            start={startDate}
                            errors={errors}
                        />
                    )}

                    <fieldset className="grid gap-1.5">
                        <legend className="mb-1.5 text-sm leading-none font-medium">
                            Cor
                        </legend>
                        <div className="flex flex-wrap gap-2">
                            {(
                                Object.entries(EVENT_COLORS) as [
                                    EventColor,
                                    (typeof EVENT_COLORS)[EventColor],
                                ][]
                            ).map(([value, option]) => (
                                <label
                                    key={value}
                                    title={option.label}
                                    className="relative cursor-pointer"
                                >
                                    <input
                                        type="radio"
                                        name="color"
                                        value={value}
                                        aria-label={option.label}
                                        checked={color === value}
                                        onChange={() => setColor(value)}
                                        className="peer sr-only"
                                    />
                                    <span
                                        aria-hidden
                                        className={cn(
                                            'block size-7 rounded-full ring-offset-2 ring-offset-background transition-shadow peer-focus-visible:ring-2 peer-focus-visible:ring-ring',
                                            option.swatch,
                                            color === value &&
                                                'ring-2 ring-foreground',
                                        )}
                                    />
                                </label>
                            ))}
                        </div>
                        <InputError message={errors.color} />
                    </fieldset>

                    <fieldset className="grid gap-1.5">
                        <legend className="mb-1.5 text-sm leading-none font-medium">
                            Ícone ·{' '}
                            <span className="font-normal text-muted-foreground">
                                {EVENT_ICONS[icon].label}
                            </span>
                        </legend>
                        <div className="grid grid-cols-8 gap-1.5">
                            {(
                                Object.entries(EVENT_ICONS) as [
                                    EventIcon,
                                    (typeof EVENT_ICONS)[EventIcon],
                                ][]
                            ).map(([value, option]) => (
                                <label
                                    key={value}
                                    title={option.label}
                                    className="relative cursor-pointer"
                                >
                                    <input
                                        type="radio"
                                        name="icon"
                                        value={value}
                                        aria-label={option.label}
                                        checked={icon === value}
                                        onChange={() => setIcon(value)}
                                        className="peer sr-only"
                                    />
                                    <span
                                        aria-hidden
                                        className={cn(
                                            'flex aspect-square items-center justify-center rounded-md border transition-colors peer-focus-visible:ring-2 peer-focus-visible:ring-ring',
                                            icon === value
                                                ? cn(
                                                      'border-l-[3px] border-transparent',
                                                      EVENT_COLORS[color].badge,
                                                  )
                                                : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                                        )}
                                    >
                                        <option.icon
                                            className={cn(
                                                'size-4',
                                                icon === value &&
                                                    EVENT_COLORS[color].icon,
                                            )}
                                        />
                                    </span>
                                </label>
                            ))}
                        </div>
                        <InputError message={errors.icon} />
                    </fieldset>

                    <FormField
                        id="event-description"
                        label="Descrição (opcional)"
                        error={errors.description}
                    >
                        <Textarea
                            id="event-description"
                            name="description"
                            maxLength={2000}
                            rows={3}
                            defaultValue={values?.description ?? ''}
                        />
                    </FormField>

                    <DialogFooter className={cn(event && 'sm:justify-between')}>
                        {event && deleteUrl && (
                            <ConfirmDialog
                                url={deleteUrl}
                                title={
                                    {
                                        occurrence:
                                            'Excluir somente este evento?',
                                        series: 'Excluir toda a série?',
                                        single: 'Excluir evento?',
                                    }[scope ?? 'single']
                                }
                                description={
                                    scope === 'occurrence'
                                        ? `“${event.title}” de ${formatDate(event.date)} será excluído; as demais repetições continuam na agenda.`
                                        : scope === 'series'
                                          ? `“${event.series?.title ?? event.title}” e todas as suas repetições serão excluídos da agenda.`
                                          : `“${event.title}” será excluído da agenda.`
                                }
                                confirmLabel="Excluir"
                                onSuccess={onDone}
                                trigger={
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        className="text-destructive"
                                    >
                                        <Trash2 />
                                        {scope === 'series'
                                            ? 'Excluir série'
                                            : 'Excluir'}
                                    </Button>
                                }
                            />
                        )}
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            Salvar
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

/**
 * The "Repetir" controls: a preset, or a custom interval and weekdays, plus
 * an optional end date. Submits the recurrence_* fields of the request.
 */
function RecurrenceFields({
    event,
    start,
    errors,
}: {
    /** The series being edited, if any. */
    event?: CalendarEvent;
    /** The first date, "YYYY-MM-DD", which anchors the rule. */
    start: string;
    errors: Record<string, string | undefined>;
}) {
    const recurrence = event?.recurrence;
    const [preset, setPreset] = useState<Preset>(() => presetOf(event, start));
    const [unit, setUnit] = useState<RecurrenceFrequency>(
        recurrence?.frequency ?? 'weekly',
    );
    const [every, setEvery] = useState(recurrence?.interval ?? 1);
    const [weekdays, setWeekdays] = useState<number[]>(
        recurrence?.frequency === 'weekly' ? recurrence.weekdays : [],
    );
    const [ends, setEnds] = useState(Boolean(recurrence?.until));
    const [until, setUntil] = useState(recurrence?.until ?? '');

    const custom = preset === 'custom';
    const frequency: RecurrenceFrequency | null =
        preset === 'none' ? null : custom ? unit : preset;
    const validStart = /^\d{4}-\d{2}-\d{2}$/.test(start);
    // The first date always belongs to the series, so its weekday stays on.
    const chosenWeekdays = validStart
        ? [...new Set([...weekdays, weekdayOf(start)])].sort((a, b) => a - b)
        : weekdays;

    const toggleWeekday = (weekday: number) =>
        setWeekdays((current) =>
            current.includes(weekday)
                ? current.filter((value) => value !== weekday)
                : [...current, weekday],
        );

    return (
        <div className="grid gap-3 rounded-lg border p-3">
            <input
                type="hidden"
                name="recurrence_frequency"
                value={frequency ?? ''}
            />
            <input
                type="hidden"
                name="recurrence_interval"
                value={custom ? every : 1}
            />
            {frequency === 'weekly' &&
                custom &&
                chosenWeekdays.map((weekday) => (
                    <input
                        key={weekday}
                        type="hidden"
                        name="recurrence_weekdays[]"
                        value={weekday}
                    />
                ))}

            <FormField
                id="event-recurrence"
                label="Repetir"
                error={errors.recurrence_frequency}
            >
                <NativeSelect
                    id="event-recurrence"
                    value={preset}
                    onChange={(change) =>
                        setPreset(change.target.value as Preset)
                    }
                >
                    {PRESETS.map(([value, label]) => (
                        <option key={value} value={value}>
                            {label}
                        </option>
                    ))}
                </NativeSelect>
            </FormField>

            {custom && (
                <div className="grid gap-1.5">
                    <span className="text-sm leading-none font-medium">
                        A cada
                    </span>
                    <div className="flex gap-2">
                        <Input
                            aria-label="Intervalo"
                            type="number"
                            min={1}
                            max={99}
                            required
                            value={every}
                            onChange={(change) =>
                                setEvery(
                                    Math.max(1, Number(change.target.value)),
                                )
                            }
                            className="w-20"
                        />
                        <NativeSelect
                            aria-label="Unidade"
                            value={unit}
                            onChange={(change) =>
                                setUnit(
                                    change.target.value as RecurrenceFrequency,
                                )
                            }
                        >
                            {(
                                Object.entries(RECURRENCE_UNITS) as [
                                    RecurrenceFrequency,
                                    (typeof RECURRENCE_UNITS)[RecurrenceFrequency],
                                ][]
                            ).map(([value, option]) => (
                                <option key={value} value={value}>
                                    {every === 1 ? option.one : option.many}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <InputError message={errors.recurrence_interval} />
                </div>
            )}

            {custom && unit === 'weekly' && (
                <fieldset className="grid gap-1.5">
                    <legend className="mb-1.5 text-sm leading-none font-medium">
                        Nos dias
                    </legend>
                    <div className="grid grid-cols-7 gap-1.5">
                        {WEEKDAYS.map((label, weekday) => {
                            const fixed =
                                validStart && weekday === weekdayOf(start);
                            const checked = chosenWeekdays.includes(weekday);

                            return (
                                <label
                                    key={label}
                                    title={
                                        fixed
                                            ? 'Dia da data de início'
                                            : undefined
                                    }
                                    className={cn(
                                        'relative',
                                        fixed
                                            ? 'cursor-not-allowed'
                                            : 'cursor-pointer',
                                    )}
                                >
                                    <input
                                        type="checkbox"
                                        aria-label={label}
                                        checked={checked}
                                        disabled={fixed}
                                        onChange={() => toggleWeekday(weekday)}
                                        className="peer sr-only"
                                    />
                                    <span
                                        aria-hidden
                                        className={cn(
                                            'flex h-8 items-center justify-center rounded-md border text-xs font-medium transition-colors peer-focus-visible:ring-2 peer-focus-visible:ring-ring',
                                            checked
                                                ? 'border-primary bg-primary text-primary-foreground'
                                                : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                                            fixed && 'opacity-80',
                                        )}
                                    >
                                        {label}
                                    </span>
                                </label>
                            );
                        })}
                    </div>
                    <InputError message={errors.recurrence_weekdays} />
                </fieldset>
            )}

            {frequency && (
                <>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField id="event-ends" label="Termina">
                            <NativeSelect
                                id="event-ends"
                                value={ends ? 'date' : 'never'}
                                onChange={(change) =>
                                    setEnds(change.target.value === 'date')
                                }
                            >
                                <option value="never">Nunca</option>
                                <option value="date">Em uma data</option>
                            </NativeSelect>
                        </FormField>
                        {ends && (
                            <FormField
                                id="event-until"
                                label="Até"
                                error={errors.recurrence_until}
                            >
                                <Input
                                    id="event-until"
                                    name="recurrence_until"
                                    type="date"
                                    required
                                    min={validStart ? start : '2000-01-01'}
                                    max="2100-12-31"
                                    value={until}
                                    onChange={(change) =>
                                        setUntil(change.target.value)
                                    }
                                />
                            </FormField>
                        )}
                    </div>
                    {validStart && (
                        <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <Repeat className="size-3.5" aria-hidden />
                            {describeRecurrence(
                                {
                                    frequency,
                                    interval: custom ? every : 1,
                                    weekdays:
                                        frequency === 'weekly' && custom
                                            ? chosenWeekdays
                                            : [],
                                    until: ends && until ? until : null,
                                },
                                start,
                            )}
                        </p>
                    )}
                </>
            )}
        </div>
    );
}
