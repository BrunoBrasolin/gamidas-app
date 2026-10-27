import { Head, Link, router } from '@inertiajs/react';
import {
    Clapperboard,
    Clock,
    Film,
    Search,
    Settings2,
    Trash2,
    Tv,
    User,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { ConfirmDialog } from '@/components/finance/confirm-dialog';
import { EmptyState } from '@/components/finance/empty-state';
import { PageHeader } from '@/components/finance/page-header';
import { StatCard } from '@/components/finance/stat-card';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import {
    ContinueWatching,
    SeriesInProgress,
} from '@/components/watched/continue-watching';
import { JellyfinConnectionCard } from '@/components/watched/jellyfin-connection';
import { capitalize, formatDateTime, formatMonth } from '@/lib/format';
import { formatDuration } from '@/lib/time';
import { WATCH_TYPE_ICONS, watchHeading } from '@/lib/watched';
import watched from '@/routes/watched';
import type {
    JellyfinConnection,
    WatchedItem,
    WatchedStats,
    WatchProgress,
} from '@/types';

/** Lowercase without accents, for searching. */
function normalize(text: string): string {
    return text
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();
}

function ItemCard({ item }: { item: WatchedItem }) {
    const Icon = WATCH_TYPE_ICONS[item.type];

    return (
        <Card className="py-3">
            <CardContent className="flex items-center gap-3 px-4">
                <Icon
                    className="size-4 shrink-0 text-muted-foreground"
                    aria-hidden
                />
                <div className="min-w-0 flex-1">
                    <Link
                        href={watched.show(item.id)}
                        className="block truncate font-medium hover:underline"
                    >
                        {watchHeading(item)}
                    </Link>
                    <p className="flex flex-wrap gap-x-4 text-sm text-muted-foreground">
                        <span>{formatDateTime(item.watched_at)}</span>
                        {item.viewer && (
                            <span className="flex items-center gap-1.5">
                                <User className="size-3.5" aria-hidden />
                                {item.viewer}
                            </span>
                        )}
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
                    description={`“${watchHeading(item)}” sai do histórico. Se for assistido de novo, volta a ser registrado.`}
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
    in_progress: inProgress,
    series,
    stats,
    jellyfin,
}: {
    year: number;
    years: number[];
    items: WatchedItem[];
    in_progress: WatchProgress[];
    series: WatchedItem[];
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
            const haystack = [
                item.title,
                item.series_name ?? '',
                item.viewer ?? '',
            ];

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
                    description="Filmes e episódios que a casa terminou no Jellyfin."
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

                {inProgress.length > 0 && (
                    <ContinueWatching items={inProgress} />
                )}

                {series.length > 0 && <SeriesInProgress episodes={series} />}

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
                                ? 'Quando alguém da casa terminar um filme ou episódio no Jellyfin, ele aparece aqui.'
                                : 'Conecte o Jellyfin para registrar automaticamente o que a casa assiste.'
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
                                placeholder="Buscar filme, série, episódio ou pessoa"
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
