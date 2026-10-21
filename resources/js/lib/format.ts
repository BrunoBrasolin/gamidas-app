const TIME_ZONE = 'America/Sao_Paulo';

const currency = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
});

const MONTHS = [
    'janeiro',
    'fevereiro',
    'março',
    'abril',
    'maio',
    'junho',
    'julho',
    'agosto',
    'setembro',
    'outubro',
    'novembro',
    'dezembro',
];

/**
 * Format integer cents as Brazilian Real, e.g. "R$ 1.234,56".
 * Amounts are only ever converted for display; all arithmetic happens on the
 * server in integer cents.
 */
export function formatMoney(cents: number): string {
    return currency.format(cents / 100);
}

/**
 * Format basis points (8000 = 80%) as a percentage, e.g. "80,00%".
 */
export function formatPercent(basisPoints: number): string {
    return `${(basisPoints / 100).toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}%`;
}

/**
 * Format a "YYYY-MM-DD" date as "21/10/2026" without timezone shifts.
 */
export function formatDate(date: string): string {
    const [year, month, day] = date.split('-');

    return `${day}/${month}/${year}`;
}

/**
 * Format a "YYYY-MM-DD" date as "21/10".
 */
export function formatShortDate(date: string): string {
    const [, month, day] = date.split('-');

    return `${day}/${month}`;
}

/**
 * Format a "YYYY-MM" month as "outubro de 2026".
 */
export function formatMonth(month: string): string {
    const [year, number] = month.split('-').map(Number);

    return `${MONTHS[number - 1]} de ${year}`;
}

/**
 * Format a "YYYY-MM" month as "out/26".
 */
export function formatShortMonth(month: string): string {
    const [year, number] = month.split('-').map(Number);

    return `${MONTHS[number - 1].slice(0, 3)}/${String(year).slice(2)}`;
}

/**
 * Format an ISO timestamp in the São Paulo timezone, e.g. "21/10/2026 14:30".
 */
export function formatDateTime(iso: string): string {
    return new Intl.DateTimeFormat('pt-BR', {
        timeZone: TIME_ZONE,
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(iso));
}

/**
 * Move a "YYYY-MM" month by a number of months.
 */
export function shiftMonth(month: string, delta: number): string {
    const [year, number] = month.split('-').map(Number);
    const index = year * 12 + (number - 1) + delta;

    return `${Math.floor(index / 12)}-${String((index % 12) + 1).padStart(2, '0')}`;
}

/**
 * Today's date in São Paulo as "YYYY-MM-DD".
 */
export function today(): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: TIME_ZONE }).format(
        new Date(),
    );
}

/**
 * Convert cents to the decimal string accepted by the server, e.g. "1234.50".
 */
export function centsToDecimal(cents: number): string {
    return `${Math.trunc(cents / 100)}.${String(Math.abs(cents % 100)).padStart(2, '0')}`;
}

/**
 * Uppercase the first letter, e.g. "outubro de 2026" → "Outubro de 2026".
 */
export function capitalize(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}
