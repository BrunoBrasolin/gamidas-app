import type { StudyColor, StudyNode, StudyPageStatus } from '@/types';

/**
 * Cover colors of the study library, matching app/Enums/StudyColor.php.
 * Each has a light and a dark tone for the cover gradient.
 */
export const STUDY_COLORS: Record<
    StudyColor,
    { label: string; light: string; dark: string }
> = {
    terracotta: { label: 'Terracota', light: '#b65a40', dark: '#7a3523' },
    ochre: { label: 'Ocre', light: '#c08a2e', dark: '#845714' },
    olive: { label: 'Oliva', light: '#80833d', dark: '#545724' },
    sage: { label: 'Sálvia', light: '#6f8f7a', dark: '#46634f' },
    teal: { label: 'Petróleo', light: '#2f7a7a', dark: '#1b5050' },
    navy: { label: 'Marinho', light: '#34507a', dark: '#1c3050' },
    plum: { label: 'Ameixa', light: '#714768', dark: '#482a43' },
    rose: { label: 'Rosé', light: '#b86a7a', dark: '#844656' },
    graphite: { label: 'Grafite', light: '#55555c', dark: '#2f2f35' },
};

export const STUDY_COLOR_KEYS = Object.keys(STUDY_COLORS) as StudyColor[];

export const STATUS_LABELS: Record<StudyPageStatus, string> = {
    unread: 'Não lida',
    in_progress: 'Estudando',
    reviewed: 'Revisada',
};

export const STATUS_ORDER: StudyPageStatus[] = [
    'unread',
    'in_progress',
    'reviewed',
];

/** Lowercase without accents, for searching. */
export function normalize(text: string): string {
    return text
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();
}

/** "1,2 MB", "340 KB". */
export function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB'];
    let value = bytes / 1024;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toLocaleString('pt-BR', { maximumFractionDigits: value < 10 ? 1 : 0 })} ${units[unit]}`;
}

const relative = new Intl.RelativeTimeFormat('pt-BR', { numeric: 'auto' });

/** "agora há pouco", "há 3 horas", "ontem", "há 2 semanas", "12/03/2026". */
export function formatRelative(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);
    const seconds = Math.round((date.getTime() - Date.now()) / 1000);
    const abs = Math.abs(seconds);

    if (abs < 60) {
        return 'agora há pouco';
    }

    if (abs < 3600) {
        return relative.format(Math.round(seconds / 60), 'minute');
    }

    if (abs < 86400) {
        return relative.format(Math.round(seconds / 3600), 'hour');
    }

    if (abs < 86400 * 7) {
        return relative.format(Math.round(seconds / 86400), 'day');
    }

    if (abs < 86400 * 30) {
        return relative.format(Math.round(seconds / (86400 * 7)), 'week');
    }

    return new Intl.DateTimeFormat('pt-BR', {
        timeZone: 'America/Sao_Paulo',
        dateStyle: 'short',
    }).format(date);
}

/** "3 páginas", "1 página". */
export function plural(count: number, one: string, many: string): string {
    return `${count.toLocaleString('pt-BR')} ${count === 1 ? one : many}`;
}

export type StudyHeading = { id: string; level: number; text: string };

/** Plain text of a node. */
export function nodeText(node: StudyNode): string {
    if (node.type === 'text') {
        return node.text ?? '';
    }

    return (node.content ?? []).map(nodeText).join('');
}

/**
 * Headings of a document in order, with the ids the reader gives them. The
 * map lets the renderer find the id of each heading node.
 */
export function collectHeadings(doc: StudyNode): {
    headings: StudyHeading[];
    ids: WeakMap<StudyNode, string>;
} {
    const headings: StudyHeading[] = [];
    const ids = new WeakMap<StudyNode, string>();

    const visit = (node: StudyNode) => {
        if (node.type === 'heading') {
            const id = `secao-${headings.length + 1}`;
            const level = Number(node.attrs?.level ?? 2);
            headings.push({ id, level, text: nodeText(node).trim() });
            ids.set(node, id);

            return;
        }

        node.content?.forEach(visit);
    };

    visit(doc);

    return { headings: headings.filter((h) => h.text !== ''), ids };
}

/** Only web and e-mail links are rendered (the server enforces it too). */
export function safeHref(href: unknown): string | null {
    if (typeof href !== 'string') {
        return null;
    }

    return /^(https?:\/\/|mailto:)/i.test(href.trim()) ? href.trim() : null;
}

/** localStorage that never throws (private mode, blocked storage). */
export const preferences = {
    get<T>(key: string, fallback: T): T {
        try {
            const raw = window.localStorage.getItem(key);

            return raw === null ? fallback : (JSON.parse(raw) as T);
        } catch {
            return fallback;
        }
    },
    set(key: string, value: unknown): void {
        try {
            window.localStorage.setItem(key, JSON.stringify(value));
        } catch {
            // Preferences are a convenience; ignore storage failures.
        }
    },
};

/** Whether the main pointer is a finger (iPad, phones). */
export function isTouchDevice(): boolean {
    return (
        typeof window !== 'undefined' &&
        window.matchMedia('(pointer: coarse)').matches
    );
}
