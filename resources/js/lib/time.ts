import type { EntryDraft, TimerSession } from '@/types';

const WEEKDAYS = [
    'domingo',
    'segunda-feira',
    'terça-feira',
    'quarta-feira',
    'quinta-feira',
    'sexta-feira',
    'sábado',
];

const SHORT_WEEKDAYS = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];

/**
 * A "YYYY-MM-DD" date as a UTC midnight Date, so calendar arithmetic never
 * shifts the day because of the browser timezone.
 */
function utcDate(date: string): Date {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day));
}

function toKey(date: Date): string {
    return date.toISOString().slice(0, 10);
}

/**
 * Move a "YYYY-MM-DD" date by a number of days.
 */
export function shiftDay(date: string, delta: number): string {
    const next = utcDate(date);
    next.setUTCDate(next.getUTCDate() + delta);

    return toKey(next);
}

/**
 * 0 = Sunday … 6 = Saturday.
 */
export function weekdayIndex(date: string): number {
    return utcDate(date).getUTCDay();
}

export function weekday(date: string): string {
    return WEEKDAYS[weekdayIndex(date)];
}

export function shortWeekday(date: string): string {
    return SHORT_WEEKDAYS[weekdayIndex(date)];
}

/**
 * Format minutes as "1h 30m", "45m" or "2h"; zero is "0m".
 */
export function formatMinutes(minutes: number): string {
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    if (hours === 0) {
        return `${rest}m`;
    }

    return rest === 0 ? `${hours}h` : `${hours}h ${rest}m`;
}

/**
 * Format minutes for an editable cell, e.g. "1:30".
 */
export function formatClock(minutes: number): string {
    return `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')}`;
}

/**
 * Format seconds as a running clock, e.g. "01:02:03".
 */
export function formatElapsed(seconds: number): string {
    const safe = Math.max(0, Math.floor(seconds));
    const hours = Math.floor(safe / 3600);
    const minutes = Math.floor((safe % 3600) / 60);
    const rest = safe % 60;

    return [hours, minutes, rest]
        .map((part) => String(part).padStart(2, '0'))
        .join(':');
}

/**
 * Parse a typed duration into whole minutes. Accepts "1:30", "1h30",
 * "1h 30m", "45m", "2h", "1,5h" / "1.5" (hours) and plain "90" (minutes).
 * Returns null when the text is not a duration; empty text is zero.
 */
export function parseDuration(text: string): number | null {
    const value = text.trim().toLowerCase().replace(/\s+/g, '');

    if (value === '') {
        return 0;
    }

    let match = /^(\d{1,4}):([0-5]?\d)$/.exec(value);

    if (match) {
        return Number(match[1]) * 60 + Number(match[2]);
    }

    match = /^(?:(\d{1,4})h)?(?:(\d{1,4})(?:m|min)?)?$/.exec(value);

    if (match && (match[1] !== undefined || match[2] !== undefined)) {
        const hours = Number(match[1] ?? 0);
        const minutes = Number(match[2] ?? 0);

        // "90" alone means minutes; "1h30" means 1 hour and 30 minutes.
        return hours * 60 + minutes;
    }

    match = /^(\d{1,4})[.,](\d{1,2})h?$/.exec(value);

    if (match) {
        return Math.round(Number(`${match[1]}.${match[2]}`) * 60);
    }

    return null;
}

/**
 * Clamp a goal percentage to the 0–100 range of a progress bar.
 */
export function clampPercent(percent: number | null): number {
    return Math.max(0, Math.min(100, percent ?? 0));
}

/**
 * Suggested values for a manual entry based on a timer session. They only
 * pre-fill a form the user reviews; nothing is saved automatically.
 */
export function sessionDraft(session: TimerSession): EntryDraft {
    return {
        task_id: session.task.id,
        date: session.started_on,
        minutes: Math.max(1, Math.round(session.elapsed_seconds / 60)),
    };
}
