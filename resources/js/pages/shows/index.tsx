import { Head, Link } from '@inertiajs/react';
import {
    Calendar,
    Images,
    ListChecks,
    ListMusic,
    MapPin,
    Mic2,
    Music,
    Plus,
    Search,
    Ticket,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/finance/empty-state';
import { PageHeader } from '@/components/finance/page-header';
import { StatCard } from '@/components/finance/stat-card';
import { showTitle } from '@/components/shows/setlist';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/format';
import shows from '@/routes/shows';
import type { Show, ShowStats } from '@/types';

/** Lowercase without accents, for searching. */
function normalize(text: string): string {
    return text
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();
}

function matches(show: Show, query: string): boolean {
    const haystack = [
        show.band,
        show.tour ?? '',
        show.venue,
        show.date.slice(0, 4),
        ...show.setlist,
        ...show.opening_acts.flatMap((act) => [act.band, ...act.setlist]),
    ];

    return haystack.some((text) => normalize(text).includes(query));
}

function ShowCard({ show }: { show: Show }) {
    return (
        <Card className="py-4 transition-colors hover:bg-accent/40">
            <CardContent className="flex flex-col gap-3 px-4 sm:flex-row sm:items-start sm:justify-between">
                <Link
                    href={shows.show(show.id)}
                    className="min-w-0 flex-1 space-y-1.5"
                    prefetch
                >
                    <p className="font-medium">{showTitle(show)}</p>
                    <p className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
                        <span className="flex items-center gap-1.5">
                            <Calendar className="size-3.5" aria-hidden />
                            {formatDate(show.date)}
                        </span>
                        <span className="flex items-center gap-1.5">
                            <MapPin className="size-3.5" aria-hidden />
                            {show.venue}
                        </span>
                        <span className="flex items-center gap-1.5">
                            <Music className="size-3.5" aria-hidden />
                            {show.setlist.length}{' '}
                            {show.setlist.length === 1 ? 'música' : 'músicas'}
                        </span>
                    </p>
                    {show.opening_acts.length > 0 && (
                        <p className="flex flex-wrap items-center gap-1.5 text-sm">
                            <span className="text-muted-foreground">
                                Abertura:
                            </span>
                            {show.opening_acts.map((act, index) => (
                                <Badge key={index} variant="secondary">
                                    {act.band}
                                </Badge>
                            ))}
                        </p>
                    )}
                </Link>
                {show.photos_url && (
                    <Button
                        variant="outline"
                        size="sm"
                        asChild
                        className="shrink-0"
                    >
                        <a
                            href={show.photos_url}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <Images />
                            Álbum
                        </a>
                    </Button>
                )}
                {show.playlist_url && (
                    <Button
                        variant="outline"
                        size="sm"
                        asChild
                        className="shrink-0"
                    >
                        <a
                            href={show.playlist_url}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <ListMusic />
                            Playlist
                        </a>
                    </Button>
                )}
            </CardContent>
        </Card>
    );
}

export default function ShowsIndex({
    shows: all,
    stats,
}: {
    shows: Show[];
    stats: ShowStats;
}) {
    const [query, setQuery] = useState('');

    const years = useMemo(() => {
        const needle = normalize(query.trim());
        const groups = new Map<string, Show[]>();

        for (const show of all) {
            if (needle !== '' && !matches(show, needle)) {
                continue;
            }

            const year = show.date.slice(0, 4);
            groups.set(year, [...(groups.get(year) ?? []), show]);
        }

        return [...groups.entries()];
    }, [all, query]);

    return (
        <>
            <Head title="Shows" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Shows"
                    description="Os shows da casa, com setlist, aberturas e álbum de fotos."
                >
                    <Button variant="outline" asChild>
                        <Link href={shows.checklist.edit()}>
                            <ListChecks />
                            Checklist
                        </Link>
                    </Button>
                    <Button asChild>
                        <Link href={shows.create()}>
                            <Plus />
                            Novo show
                        </Link>
                    </Button>
                </PageHeader>

                {all.length === 0 ? (
                    <EmptyState
                        icon={Ticket}
                        title="Nenhum show ainda"
                        description="Cadastre o primeiro show com a banda, a tour, o local, a data e o setlist."
                    />
                ) : (
                    <>
                        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                            <StatCard
                                title="Shows"
                                value={stats.shows}
                                icon={Ticket}
                            />
                            <StatCard
                                title="Bandas"
                                value={stats.bands}
                                hint="Incluindo aberturas"
                                icon={Mic2}
                            />
                            <StatCard
                                title="Locais"
                                value={stats.venues}
                                icon={MapPin}
                            />
                            <StatCard
                                title="Músicas"
                                value={stats.songs.toLocaleString('pt-BR')}
                                hint="Somando todos os setlists"
                                icon={ListMusic}
                            />
                        </div>

                        <div className="relative max-w-md">
                            <Search
                                className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                aria-hidden
                            />
                            <Input
                                type="search"
                                placeholder="Buscar banda, tour, local ou música"
                                aria-label="Buscar shows"
                                className="pl-9"
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                            />
                        </div>

                        {years.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nenhum show encontrado.
                            </p>
                        ) : (
                            years.map(([year, items]) => (
                                <section key={year} className="space-y-3">
                                    <h2 className="text-sm font-medium text-muted-foreground">
                                        {year} · {items.length}{' '}
                                        {items.length === 1 ? 'show' : 'shows'}
                                    </h2>
                                    <ul className="grid gap-3">
                                        {items.map((show) => (
                                            <li key={show.id}>
                                                <ShowCard show={show} />
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

ShowsIndex.layout = {
    breadcrumbs: [{ title: 'Shows', href: shows.index() }],
};
