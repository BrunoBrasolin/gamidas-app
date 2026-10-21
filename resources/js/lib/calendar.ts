import {
    Bell,
    Briefcase,
    Cake,
    Calendar,
    Car,
    Dumbbell,
    GraduationCap,
    Heart,
    Home,
    PartyPopper,
    PawPrint,
    Plane,
    ShoppingCart,
    Stethoscope,
    Users,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { formatDate, formatMonth, formatShortDate } from '@/lib/format';
import type {
    CalendarEvent,
    EventColor,
    EventIcon,
    Recurrence,
    RecurrenceFrequency,
} from '@/types';

/**
 * Tailwind classes for each badge color. Kept as full literal strings so
 * Tailwind can find them; values match App\Enums\EventColor.
 */
export const EVENT_COLORS: Record<
    EventColor,
    { label: string; swatch: string; badge: string; icon: string }
> = {
    slate: {
        label: 'Cinza',
        swatch: 'bg-slate-500',
        badge: 'border-l-slate-500 bg-slate-100 text-slate-900 dark:bg-slate-500/20 dark:text-slate-100',
        icon: 'text-slate-600 dark:text-slate-300',
    },
    red: {
        label: 'Vermelho',
        swatch: 'bg-red-500',
        badge: 'border-l-red-500 bg-red-50 text-red-900 dark:bg-red-500/20 dark:text-red-100',
        icon: 'text-red-600 dark:text-red-300',
    },
    orange: {
        label: 'Laranja',
        swatch: 'bg-orange-500',
        badge: 'border-l-orange-500 bg-orange-50 text-orange-900 dark:bg-orange-500/20 dark:text-orange-100',
        icon: 'text-orange-600 dark:text-orange-300',
    },
    amber: {
        label: 'Amarelo',
        swatch: 'bg-amber-400',
        badge: 'border-l-amber-400 bg-amber-50 text-amber-900 dark:bg-amber-400/20 dark:text-amber-100',
        icon: 'text-amber-600 dark:text-amber-300',
    },
    green: {
        label: 'Verde',
        swatch: 'bg-emerald-500',
        badge: 'border-l-emerald-500 bg-emerald-50 text-emerald-900 dark:bg-emerald-500/20 dark:text-emerald-100',
        icon: 'text-emerald-600 dark:text-emerald-300',
    },
    teal: {
        label: 'Turquesa',
        swatch: 'bg-teal-500',
        badge: 'border-l-teal-500 bg-teal-50 text-teal-900 dark:bg-teal-500/20 dark:text-teal-100',
        icon: 'text-teal-600 dark:text-teal-300',
    },
    blue: {
        label: 'Azul',
        swatch: 'bg-blue-500',
        badge: 'border-l-blue-500 bg-blue-50 text-blue-900 dark:bg-blue-500/20 dark:text-blue-100',
        icon: 'text-blue-600 dark:text-blue-300',
    },
    violet: {
        label: 'Roxo',
        swatch: 'bg-violet-500',
        badge: 'border-l-violet-500 bg-violet-50 text-violet-900 dark:bg-violet-500/20 dark:text-violet-100',
        icon: 'text-violet-600 dark:text-violet-300',
    },
    pink: {
        label: 'Rosa',
        swatch: 'bg-pink-500',
        badge: 'border-l-pink-500 bg-pink-50 text-pink-900 dark:bg-pink-500/20 dark:text-pink-100',
        icon: 'text-pink-600 dark:text-pink-300',
    },
};

/**
 * Predefined event icons; values match App\Enums\EventIcon.
 */
export const EVENT_ICONS: Record<
    EventIcon,
    { label: string; icon: LucideIcon }
> = {
    calendar: { label: 'Evento', icon: Calendar },
    reminder: { label: 'Lembrete', icon: Bell },
    cake: { label: 'Aniversário', icon: Cake },
    party: { label: 'Comemoração', icon: PartyPopper },
    heart: { label: 'Especial', icon: Heart },
    family: { label: 'Família', icon: Users },
    health: { label: 'Saúde', icon: Stethoscope },
    sport: { label: 'Esporte', icon: Dumbbell },
    work: { label: 'Trabalho', icon: Briefcase },
    study: { label: 'Estudos', icon: GraduationCap },
    travel: { label: 'Viagem', icon: Plane },
    home: { label: 'Casa', icon: Home },
    car: { label: 'Carro', icon: Car },
    shopping: { label: 'Compras', icon: ShoppingCart },
    money: { label: 'Finanças', icon: Wallet },
    pet: { label: 'Pet', icon: PawPrint },
};

export const WEEKDAYS = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

const LONG_WEEKDAYS = [
    'domingo',
    'segunda-feira',
    'terça-feira',
    'quarta-feira',
    'quinta-feira',
    'sexta-feira',
    'sábado',
];

/**
 * Units of a recurrence interval, singular and plural; values match
 * App\Enums\RecurrenceFrequency.
 */
export const RECURRENCE_UNITS: Record<
    RecurrenceFrequency,
    { label: string; one: string; many: string }
> = {
    daily: { label: 'Diariamente', one: 'dia', many: 'dias' },
    weekly: { label: 'Semanalmente', one: 'semana', many: 'semanas' },
    monthly: { label: 'Mensalmente', one: 'mês', many: 'meses' },
    yearly: { label: 'Anualmente', one: 'ano', many: 'anos' },
};

function toDate(date: string): Date {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day));
}

function toKey(date: Date): string {
    return date.toISOString().slice(0, 10);
}

/**
 * The weeks shown for a "YYYY-MM" month, from the Sunday on or before the
 * 1st to the Saturday on or after the last day (same range as the server).
 */
export function monthWeeks(month: string): string[][] {
    const first = toDate(`${month}-01`);
    const start = new Date(first);
    start.setUTCDate(1 - first.getUTCDay());

    const last = new Date(first);
    last.setUTCMonth(last.getUTCMonth() + 1, 0);
    const end = new Date(last);
    end.setUTCDate(last.getUTCDate() + (6 - last.getUTCDay()));

    const weeks: string[][] = [];

    for (const day = start; day <= end; day.setUTCDate(day.getUTCDate() + 1)) {
        if (day.getUTCDay() === 0) {
            weeks.push([]);
        }

        weeks[weeks.length - 1].push(toKey(day));
    }

    return weeks;
}

/**
 * The long weekday name of a "YYYY-MM-DD" date, e.g. "quarta-feira".
 */
export function weekdayName(date: string): string {
    return LONG_WEEKDAYS[toDate(date).getUTCDay()];
}

/**
 * The weekday number of a "YYYY-MM-DD" date, 0 = Sunday.
 */
export function weekdayOf(date: string): number {
    return toDate(date).getUTCDay();
}

/**
 * Unique key of an event on the agenda: occurrences of a series share its id.
 */
export function eventKey(event: CalendarEvent): string {
    return `${event.id}:${event.occurrence ?? ''}`;
}

/**
 * Describe a recurrence rule starting on a "YYYY-MM-DD" date, e.g.
 * "Toda quarta-feira", "A cada 2 semanas: seg, qua" or "Todo dia 15, até 31/12/2026".
 */
export function describeRecurrence(
    recurrence: Pick<
        Recurrence,
        'frequency' | 'interval' | 'weekdays' | 'until'
    >,
    start: string,
): string {
    const { frequency, interval, until } = recurrence;
    const day = Number(start.slice(8));
    const weekdays = recurrence.weekdays.length
        ? [...recurrence.weekdays].sort((a, b) => a - b)
        : [weekdayOf(start)];
    const unit = RECURRENCE_UNITS[frequency];
    let text: string;

    if (frequency === 'weekly' && weekdays.length === 1) {
        const name = LONG_WEEKDAYS[weekdays[0]];
        // Saturday and Sunday are masculine in Portuguese.
        const every = weekdays[0] === 0 || weekdays[0] === 6 ? 'Todo' : 'Toda';

        text =
            interval === 1
                ? `${every} ${name}`
                : `A cada ${interval} semanas, ${name}`;
    } else {
        text =
            interval === 1
                ? {
                      daily: 'Todos os dias',
                      weekly: 'Toda semana',
                      monthly: `Todo dia ${day}`,
                      yearly: `Todo ano em ${formatShortDate(start)}`,
                  }[frequency]
                : `A cada ${interval} ${unit.many}`;

        if (frequency === 'weekly') {
            text += `: ${weekdays.map((weekday) => WEEKDAYS[weekday].toLowerCase()).join(', ')}`;
        } else if (interval > 1 && frequency === 'monthly') {
            text += `, no dia ${day}`;
        } else if (interval > 1 && frequency === 'yearly') {
            text += `, em ${formatShortDate(start)}`;
        }
    }

    return until ? `${text}, até ${formatDate(until)}` : text;
}

/**
 * Format a "YYYY-MM-DD" date as "quarta-feira, 21 de outubro de 2026".
 */
export function formatLongDate(date: string): string {
    return `${weekdayName(date)}, ${Number(date.slice(8))} de ${formatMonth(date.slice(0, 7))}`;
}

/**
 * Format when an event happens, e.g. "qua, 21/10 · 14:30".
 */
export function formatEventWhen(event: CalendarEvent): string {
    const day = `${WEEKDAYS[toDate(event.date).getUTCDay()].toLowerCase()}, ${formatShortDate(event.date)}`;

    return event.time ? `${day} · ${event.time}` : day;
}

/**
 * Group events by their "YYYY-MM-DD" date, keeping their order.
 */
export function groupByDate(
    events: CalendarEvent[],
): Map<string, CalendarEvent[]> {
    const groups = new Map<string, CalendarEvent[]>();

    for (const event of events) {
        groups.set(event.date, [...(groups.get(event.date) ?? []), event]);
    }

    return groups;
}
