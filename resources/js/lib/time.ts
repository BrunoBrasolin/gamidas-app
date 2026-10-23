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
 * Format seconds as a spreadsheet-style clock, e.g. "7:42:14" (hours are not
 * padded, so 90 seconds is "0:01:30").
 */
export function formatClock(seconds: number): string {
    const safe = Math.max(0, Math.floor(seconds));
    const hours = Math.floor(safe / 3600);
    const minutes = Math.floor((safe % 3600) / 60);
    const rest = safe % 60;

    return `${hours}:${String(minutes).padStart(2, '0')}:${String(rest).padStart(2, '0')}`;
}

/**
 * Format seconds as a short duration: "7h 42m", "45m", "2h" or "0m".
 */
export function formatDuration(seconds: number): string {
    const totalMinutes = Math.floor(Math.max(0, seconds) / 60);
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;

    if (hours === 0) {
        return `${minutes}m`;
    }

    return minutes === 0 ? `${hours}h` : `${hours}h ${minutes}m`;
}

/**
 * Parse a typed duration into whole seconds. Accepts "7:42:14", "1:30"
 * (hours and minutes), "1h30", "1h 30m", "45m", "2h", "1,5h" and a plain
 * "90" (minutes). Returns null when the text is not a duration; empty text
 * is zero.
 */
export function parseClock(text: string): number | null {
    const value = text.trim().toLowerCase().replace(/\s+/g, '');

    if (value === '') {
        return 0;
    }

    let match = /^(\d{1,2}):([0-5]?\d):([0-5]?\d)$/.exec(value);

    if (match) {
        return (
            Number(match[1]) * 3600 + Number(match[2]) * 60 + Number(match[3])
        );
    }

    match = /^(\d{1,2}):([0-5]?\d)$/.exec(value);

    if (match) {
        return Number(match[1]) * 3600 + Number(match[2]) * 60;
    }

    match = /^(?:(\d{1,2})h)?(?:(\d{1,4})(?:m|min)?)?$/.exec(value);

    if (match && (match[1] !== undefined || match[2] !== undefined)) {
        return Number(match[1] ?? 0) * 3600 + Number(match[2] ?? 0) * 60;
    }

    match = /^(\d{1,2})[.,](\d{1,2})h?$/.exec(value);

    if (match) {
        return Math.round(Number(`${match[1]}.${match[2]}`) * 3600);
    }

    return null;
}
