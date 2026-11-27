import type { SteamAccount } from '@/types';

const ASSETS =
    'https://shared.cloudflare.steamstatic.com/store_item_assets/steam/apps';

/** The 460×215 store header of a game. */
export function headerUrl(appId: number): string {
    return `${ASSETS}/${appId}/header.jpg`;
}

/** The 600×900 library cover (not every game has one). */
export function coverUrl(appId: number): string {
    return `${ASSETS}/${appId}/library_600x900.jpg`;
}

/**
 * Playtime like Steam shows it: "45 min", "1,5 h", "123 h".
 */
export function formatPlaytime(minutes: number): string {
    if (minutes < 60) {
        return `${minutes} min`;
    }

    const hours = minutes / 60;

    return `${hours.toLocaleString('pt-BR', { maximumFractionDigits: hours < 10 ? 1 : 0 })} h`;
}

/**
 * Hundredths of a percent as "12,3%" (one decimal under 10%, none above).
 */
export function formatShare(basisPoints: number): string {
    const percent = basisPoints / 100;

    return `${percent.toLocaleString('pt-BR', { maximumFractionDigits: percent < 10 ? 1 : 0 })}%`;
}

/**
 * Share of the game's achievements unlocked, 0–100, or null when unknown.
 */
export function completion(game: {
    achievements_total: number | null;
    achievements_unlocked: number | null;
}): number | null {
    if (!game.achievements_total || game.achievements_unlocked === null) {
        return null;
    }

    return (game.achievements_unlocked / game.achievements_total) * 100;
}

const PERSONA_STATES = [
    'Offline',
    'Online',
    'Ocupado',
    'Ausente',
    'Soneca',
    'Querendo trocar',
    'Querendo jogar',
];

/**
 * What an account (or friend) is doing: what it is playing, or its persona
 * state.
 */
export function personaStatus(
    account: Pick<SteamAccount, 'playing' | 'persona_state'>,
): string {
    if (account.playing) {
        return `Jogando ${account.playing.name}`;
    }

    return PERSONA_STATES[account.persona_state] ?? 'Offline';
}

/**
 * A stat value in pt-BR, with at most two decimals.
 */
export function formatStat(value: number): string {
    return value.toLocaleString('pt-BR', { maximumFractionDigits: 2 });
}

/**
 * A readable name for a stat without one: "total_kills_ak47" → "Total kills ak47".
 */
export function statLabel(stat: {
    name: string;
    label: string | null;
}): string {
    if (stat.label) {
        return stat.label;
    }

    const words = stat.name
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replace(/[_.-]+/g, ' ')
        .trim()
        .toLowerCase();

    return words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * The São Paulo date of an ISO timestamp, e.g. "21/10/2026".
 */
export function formatDay(iso: string): string {
    return new Intl.DateTimeFormat('pt-BR', {
        timeZone: 'America/Sao_Paulo',
        dateStyle: 'short',
    }).format(new Date(iso));
}

/**
 * Whole years since an ISO date.
 */
export function yearsSince(iso: string): number {
    const start = new Date(iso);
    const now = new Date();
    let years = now.getFullYear() - start.getFullYear();

    if (
        now.getMonth() < start.getMonth() ||
        (now.getMonth() === start.getMonth() && now.getDate() < start.getDate())
    ) {
        years--;
    }

    return Math.max(0, years);
}

/**
 * Lowercase without accents, for searching.
 */
export function normalize(text: string): string {
    return text
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();
}
