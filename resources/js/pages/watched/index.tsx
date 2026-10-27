import { Head, router } from '@inertiajs/react';
import {
    Clapperboard,
    Clock,
    Film,
    Search,
    Settings2,
    Trash2,
    Tv,
    Video,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ConfirmDialog } from '@/components/finance/confirm-dialog';
import { EmptyState } from '@/components/finance/empty-state';
import { PageHeader } from '@/components/finance/page-header';
import { StatCard } from '@/components/finance/stat-card';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { JellyfinConnectionCard } from '@/components/watched/jellyfin-connection';
import { capitalize, formatDateTime, formatMonth } from '@/lib/format';
import { formatDuration } from '@/lib/time';
import watched from '@/routes/watched';
import type {
    JellyfinConnection,
    WatchedItem,
    WatchedStats,
    WatchType,
} from '@/types';

const TYPE_ICONS: Record<WatchType, LucideIcon> = {
    movie: Film,
    episode: Tv,
    video: Video,
};

/** Lowercase without accents, for searching. */
function normalize(text: string): string {
    return text
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();
}

/** "T01E03", or whichever part is known. */
function episodeCode(item: WatchedItem): string | null {
    const pad = (value: number) => String(value).padStart(2, '0');
    const parts = [
        item.season_number !== null ? `T${pad(item.season_number)}` : '',
        item.episode_number !== null ? `E${pad(item.episode_number)}` : '',
    ].join('');

    return parts === '' ? null : parts;
}

function heading(item: WatchedItem): string {
    if (item.type === 'episode' && item.series_name) {
        return [item.series_name, episodeCode(item), item.title]
            .filter(Boolean)
            .join(' · ');
    }

    return item.year ? `${item.title} (${item.year})` : item.title;
}

function ItemCard({ item }: { item: WatchedItem }) {
    const Icon = TYPE_ICONS[item.type];

    return (
        <Card className="py-3">
            <CardContent className="flex items-center gap-3 px-4">
                <Icon
                    className="size-4 shrink-0 text-muted-foreground"
                    aria-hidden
                />
                <div className="min-w-0 flex-1">
                    <p className="truncate font-medium">{heading(item)}</p>
                    <p className="flex flex-wrap gap-x-4 text-sm text-muted-foreground">
                        <span>{formatDateTime(item.watched_at)}</span>
                        {item.runtime_seconds !== null && (
                            <span className="flex items-center gap-1.5">
                                <Clock className="size-3.5" aria-hidden />
                                {formatDuration(item.runtime_seconds)}
                            </span>
                        )}
                    </p>
                </div>
                <ConfirmDialog
                    trigger={
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label={`Remover ${item.title}`}
                        >
                            <Trash2 />
                        </Button>
                    }
                    title="Remover do histórico?"
                    description={`“${heading(item)}” sai do histórico. Se for assistido de novo, volta a ser registrado.`}
                    confirmLabel="Remover"
                    url={watched.destroy.url(item.id)}
                />
            </CardContent>
        </Card>
    );
}

export default function WatchedIndex({
    year,
    years,
    items,
    stats,
    jellyfin,
}: {
    year: number;
    years: number[];
    items: WatchedItem[];
    stats: WatchedStats;
    jellyfin: JellyfinConnection;
}) {
    const [query, setQuery] = useState('');
    const [showSetup, setShowSetup] = useState(
        !jellyfin.connected || jellyfin.new_token !== null,
    );

    const months = useMemo(() => {
        const needle = normalize(query.trim());
        const groups = new Map<string, WatchedItem[]>();

        for (const item of items) {
            const haystack = [item.title, item.series_name ?? ''];

            if (
                needle !== '' &&
                !haystack.some((text) => normalize(text).includes(needle))
            ) {
                continue;
            }

            const month = item.watched_on.slice(0, 7);
            groups.set(month, [...(groups.get(month) ?? []), item]);
        }

        return [...groups.entries()];
    }, [items, query]);

    return (
        <>
            <Head title="Assistidos" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Assistidos"
                    description="Filmes e episódios que você terminou no Jellyfin."
                >
                    <NativeSelect
                        aria-label="Ano"
                        value={year}
                        onChange={(event) =>
                            router.get(
                                watched.index.url({
                                    query: { year: event.target.value },
                                }),
                                {},
                                { preserveScroll: true },
                            )
                        }
                        className="w-28"
                    >
                        {years.map((option) => (
                            <option key={option} value={option}>
                                {option}
                            </option>
                        ))}
                    </NativeSelect>
                    {jellyfin.connected && (
                        <Button
                            variant="outline"
                            onClick={() => setShowSetup(!showSetup)}
                            aria-expanded={showSetup}
                        >
                            <Settings2 />
                            Jellyfin
                        </Button>
                    )}
                </PageHeader>

                {showSetup && <JellyfinConnectionCard jellyfin={jellyfin} />}

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <StatCard
                        title="Assistidos"
                        value={stats.items}
                        hint={`Em ${year}`}
                        icon={Clapperboard}
                    />
                    <StatCard title="Filmes" value={stats.movies} icon={Film} />
                    <StatCard
                        title="Episódios"
                        value={stats.episodes}
                        hint={`${stats.series} ${stats.series === 1 ? 'série' : 'séries'}`}
                        icon={Tv}
                    />
                    <StatCard
                        title="Tempo assistido"
                        value={formatDuration(stats.seconds)}
                        icon={Clock}
                    />
                </div>

                {items.length === 0 ? (
                    <EmptyState
                        icon={Clapperboard}
                        title={`Nada assistido em ${year}`}
                        description={
                            jellyfin.connected
                                ? 'Quando você terminar um filme ou episódio no Jellyfin, ele aparece aqui.'
                                : 'Conecte o Jellyfin para registrar automaticamente o que você assiste.'
                        }
                    />
                ) : (
                    <>
                        <div className="relative max-w-md">
                            <Search
                                className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                aria-hidden
                            />
                            <Input
                                type="search"
                                placeholder="Buscar filme, série ou episódio"
                                aria-label="Buscar no histórico"
                                className="pl-9"
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                            />
                        </div>

                        {months.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nada encontrado.
                            </p>
                        ) : (
                            months.map(([month, entries]) => (
                                <section key={month} className="space-y-3">
                                    <h2 className="text-sm font-medium text-muted-foreground">
                                        {capitalize(formatMonth(month))} ·{' '}
                                        {entries.length}
                                    </h2>
                                    <ul className="grid gap-2">
                                        {entries.map((item) => (
                                            <li key={item.id}>
                                                <ItemCard item={item} />
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            ))
                        )}
                    </>
                )}
            </div>
        </>
    );
}

WatchedIndex.layout = {
    breadcrumbs: [{ title: 'Assistidos', href: watched.index() }],
};
