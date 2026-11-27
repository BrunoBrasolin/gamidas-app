import { Head, Link } from '@inertiajs/react';
import { Search, Ticket } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/finance/empty-state';
import { PageHeader } from '@/components/finance/page-header';
import { ShareTierList } from '@/components/tiers/share-tier-list';
import { ShowArt, showDescription } from '@/components/tiers/show-art';
import { TierBoard } from '@/components/tiers/tier-board';
import type { TierOrder } from '@/components/tiers/tier-board';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { normalize } from '@/lib/games';
import shows from '@/routes/shows';
import type { RankableShow } from '@/types';

export default function ShowsTiers({
    tiers,
    shows: all,
    shareUrl,
}: {
    tiers: TierOrder;
    shows: RankableShow[];
    shareUrl: string | null;
}) {
    const [query, setQuery] = useState('');
    const byId = useMemo(
        () => new Map(all.map((show) => [show.id, show])),
        [all],
    );

    function item(id: number) {
        const show = byId.get(id);

        if (!show) {
            return null;
        }

        return {
            name: show.band,
            title: showDescription(show),
            href: shows.show.url(show.id),
            art: <ShowArt show={show} />,
        };
    }

    function pool(placed: Set<number>): number[] {
        const needle = normalize(query.trim());

        return all
            .filter(
                (show) =>
                    !placed.has(show.id) &&
                    (needle === '' ||
                        normalize(showDescription(show)).includes(needle)),
            )
            .map((show) => show.id);
    }

    return (
        <>
            <Head title="Tier list de shows" />
            <div className="flex flex-1 flex-col gap-6 p-4 pb-28">
                <PageHeader
                    title="Tier list de shows"
                    description="O seu ranking dos shows da casa. Cada pessoa tem o próprio."
                >
                    <Button variant="outline" asChild>
                        <Link href={shows.index()}>
                            <Ticket />
                            Todos os shows
                        </Link>
                    </Button>
                    <ShareTierList
                        kind="shows"
                        url={shareUrl}
                        visible="a banda, a tour, o local e a data de cada show classificado (sem setlist nem álbum)"
                    />
                </PageHeader>

                {all.length === 0 ? (
                    <EmptyState
                        icon={Ticket}
                        title="Nenhum show para classificar"
                        description="Os shows cadastrados aparecem aqui a partir do dia em que acontecem."
                    />
                ) : (
                    <TierBoard
                        saved={tiers}
                        saveUrl={shows.tiers.update.url()}
                        item={item}
                        tileClassName="w-24 sm:w-28"
                        summary={(placed) => {
                            const left = all.length - placed.size;

                            return (
                                <>
                                    Arraste os shows para os tiers, ou toque num
                                    show e depois na letra do tier.{' '}
                                    {placed.size}{' '}
                                    {placed.size === 1
                                        ? 'show classificado'
                                        : 'shows classificados'}
                                    {left > 0 &&
                                        ` · ${left} ${left === 1 ? 'falta' : 'faltam'}`}
                                    .
                                </>
                            );
                        }}
                        pool={pool}
                        filterKey={query}
                        emptyPool={
                            query.trim() !== ''
                                ? 'Nenhum show encontrado.'
                                : 'Todos os shows já estão na tier list.'
                        }
                        openLabel="Abrir a página do show"
                        poolControls={
                            <div className="relative w-full sm:w-64">
                                <Search
                                    className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                    aria-hidden
                                />
                                <Input
                                    type="search"
                                    placeholder="Buscar banda, tour ou local"
                                    aria-label="Buscar show para classificar"
                                    className="pl-9"
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                />
                            </div>
                        }
                    />
                )}
            </div>
        </>
    );
}

ShowsTiers.layout = {
    breadcrumbs: [
        { title: 'Shows', href: shows.index() },
        { title: 'Tier list', href: shows.tiers() },
    ],
};
