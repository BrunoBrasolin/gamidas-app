import { Head } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { PageHeader } from '@/components/finance/page-header';
import { GameImage } from '@/components/games/game-image';
import { GamesTabs } from '@/components/games/games-tabs';
import { ShareTierList } from '@/components/tiers/share-tier-list';
import { TierBoard } from '@/components/tiers/tier-board';
import type { TierOrder } from '@/components/tiers/tier-board';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { coverUrl, formatPlaytime, headerUrl, normalize } from '@/lib/games';
import { TIERS } from '@/lib/tiers';
import games from '@/routes/games';
import type { ListGame, SteamGame, Tier } from '@/types';

export default function GamesTiers({
    tiers,
    library,
    shareUrl,
}: {
    tiers: Record<Tier, ListGame[]>;
    library: SteamGame[];
    shareUrl: string | null;
}) {
    const [query, setQuery] = useState('');
    const [withUnplayed, setWithUnplayed] = useState(false);

    const saved = useMemo(
        () =>
            Object.fromEntries(
                TIERS.map(({ tier }) => [
                    tier,
                    (tiers[tier] ?? []).map((game) => game.app_id),
                ]),
            ) as TierOrder,
        [tiers],
    );

    /** Every game the page can show, by app id. */
    const byApp = useMemo(() => {
        const map = new Map<number, ListGame>();

        for (const game of library) {
            map.set(game.app_id, {
                app_id: game.app_id,
                name: game.name,
                game_id: game.id,
                playtime_minutes: game.playtime_minutes,
                last_played_at: game.last_played_at,
            });
        }

        for (const { tier } of TIERS) {
            for (const game of tiers[tier] ?? []) {
                if (!map.has(game.app_id)) {
                    map.set(game.app_id, game);
                }
            }
        }

        return map;
    }, [library, tiers]);

    function item(appId: number) {
        const game = byApp.get(appId);

        if (!game) {
            return null;
        }

        return {
            name: game.name,
            title: game.playtime_minutes
                ? `${game.name} · ${formatPlaytime(game.playtime_minutes)}`
                : game.name,
            href: game.game_id === null ? null : games.show.url(game.game_id),
            art: (
                <GameImage
                    urls={[coverUrl(appId), headerUrl(appId)]}
                    name={game.name}
                    className="aspect-[2/3] w-full rounded-md border"
                />
            ),
        };
    }

    function pool(placed: Set<number>): number[] {
        const needle = normalize(query.trim());

        return library
            .filter(
                (game) =>
                    !placed.has(game.app_id) &&
                    (withUnplayed ||
                        game.playtime_minutes > 0 ||
                        needle !== '') &&
                    (needle === '' || normalize(game.name).includes(needle)),
            )
            .map((game) => game.app_id);
    }

    return (
        <>
            <Head title="Tier list" />
            <div className="flex flex-1 flex-col gap-6 p-4 pb-28">
                <PageHeader
                    title="Jogos"
                    description="Sua biblioteca da Steam: jogos, tempo de jogo, conquistas e estatísticas."
                >
                    <ShareTierList
                        kind="games"
                        url={shareUrl}
                        visible="os jogos de cada tier (sem o tempo de jogo)"
                    />
                </PageHeader>

                <GamesTabs />

                <TierBoard
                    saved={saved}
                    saveUrl={games.tiers.update.url()}
                    item={item}
                    tileClassName="w-14 sm:w-16"
                    summary={(placed) => {
                        const left = library.filter(
                            (game) =>
                                game.playtime_minutes > 0 &&
                                !placed.has(game.app_id),
                        ).length;

                        return (
                            <>
                                Arraste os jogos para os tiers, ou toque num
                                jogo e depois na letra do tier. {placed.size}{' '}
                                {placed.size === 1
                                    ? 'jogo classificado'
                                    : 'jogos classificados'}
                                {left > 0 &&
                                    ` · ${left} ${left === 1 ? 'jogado falta' : 'jogados faltam'}`}
                                .
                            </>
                        );
                    }}
                    pool={pool}
                    filterKey={`${withUnplayed}:${query}`}
                    emptyPool={
                        query.trim() !== ''
                            ? 'Nenhum jogo encontrado.'
                            : 'Todos os jogos que você jogou já estão na tier list.'
                    }
                    openLabel="Abrir a página do jogo"
                    poolControls={
                        <>
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="tiers-unplayed"
                                    checked={withUnplayed}
                                    onCheckedChange={(value) =>
                                        setWithUnplayed(value === true)
                                    }
                                />
                                <Label
                                    htmlFor="tiers-unplayed"
                                    className="text-sm font-normal"
                                >
                                    Incluir os nunca jogados
                                </Label>
                            </div>
                            <div className="relative w-full sm:w-56">
                                <Search
                                    className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                    aria-hidden
                                />
                                <Input
                                    type="search"
                                    placeholder="Buscar jogo"
                                    aria-label="Buscar jogo para classificar"
                                    className="pl-9"
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                />
                            </div>
                        </>
                    }
                />
            </div>
        </>
    );
}

GamesTiers.layout = {
    breadcrumbs: [
        { title: 'Jogos', href: games.index() },
        { title: 'Tier list', href: games.tiers() },
    ],
};
