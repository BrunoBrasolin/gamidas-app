/**
 * Durations of the complementary hours module, kept in minutes.
 */

/** "4h", "1h30", "45min" or "0h". */
export function formatHours(minutes: number): string {
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    if (hours === 0 && rest > 0) {
        return `${rest}min`;
    }

    return rest === 0
        ? `${hours}h`
        : `${hours}h${String(rest).padStart(2, '0')}`;
}

/** The value of an hours input: "4" or "1:30". */
export function hoursInput(minutes: number): string {
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return rest === 0
        ? String(hours)
        : `${hours}:${String(rest).padStart(2, '0')}`;
}

/** "312 KB" or "1,4 MB". */
export function formatFileSize(bytes: number): string {
    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / 1024 / 1024).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} MB`;
}
