import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Crown,
    ExternalLink,
    Search,
    X,
} from 'lucide-react';
import type { DragEvent } from 'react';
import { useMemo, useRef, useState } from 'react';
import { PageHeader } from '@/components/finance/page-header';
import { GameImage } from '@/components/games/game-image';
import { GamesTabs } from '@/components/games/games-tabs';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    coverUrl,
    formatPlaytime,
    headerUrl,
    normalize,
    TIERS,
} from '@/lib/games';
import { cn } from '@/lib/utils';
import games from '@/routes/games';
import type { GameTier, ListGame, SteamGame } from '@/types';

type Order = Record<GameTier, number[]>;

/** Where a dragged game would land: a tier (null = off the list) and the game it goes before. */
type Drop = { tier: GameTier | null; before: number | null };

const POOL_PAGE = 60;

function orderOf(tiers: Record<GameTier, ListGame[]>): Order {
    return Object.fromEntries(
        TIERS.map(({ tier }) => [
            tier,
            (tiers[tier] ?? []).map((game) => game.app_id),
        ]),
    ) as Order;
}

/** The order with a game moved into a tier (before another game, or last), or off the list. */
function moved(
    order: Order,
    appId: number,
    tier: GameTier | null,
    before: number | null,
): Order {
    const next = Object.fromEntries(
        TIERS.map(({ tier: key }) => [
            key,
            order[key].filter((id) => id !== appId),
        ]),
    ) as Order;

    if (tier !== null) {
        const list = next[tier];
        const at = before === null ? -1 : list.indexOf(before);
        list.splice(at < 0 ? list.length : at, 0, appId);
    }

    return next;
}

function same(a: Order, b: Order): boolean {
    return TIERS.every(({ tier }) => a[tier].join(',') === b[tier].join(','));
}

function Tile({
    game,
    selected,
    dropBefore,
    onSelect,
    onDragStart,
    onDragOver,
    onDragEnd,
}: {
    game: ListGame;
    selected: boolean;
    dropBefore: boolean;
    onSelect: () => void;
    onDragStart: () => void;
    onDragOver: (event: DragEvent<HTMLButtonElement>) => void;
    onDragEnd: () => void;
}) {
    return (
        <button
            type="button"
            draggable
            onClick={onSelect}
            onDragStart={(event) => {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', String(game.app_id));
                onDragStart();
            }}
            onDragOver={onDragOver}
            onDragEnd={onDragEnd}
            title={
                game.playtime_minutes
                    ? `${game.name} · ${formatPlaytime(game.playtime_minutes)}`
                    : game.name
            }
            aria-label={game.name}
            aria-pressed={selected}
            className={cn(
                'relative w-14 shrink-0 cursor-grab rounded-md transition-transform outline-none focus-visible:ring-2 focus-visible:ring-ring active:cursor-grabbing sm:w-16',
                selected &&
                    'ring-2 ring-primary ring-offset-2 ring-offset-background',
                dropBefore &&
                    'before:absolute before:inset-y-0 before:-left-1.5 before:w-1 before:rounded-full before:bg-primary',
            )}
        >
            <GameImage
                urls={[coverUrl(game.app_id), headerUrl(game.app_id)]}
                name={game.name}
                className="pointer-events-none aspect-[2/3] w-full rounded-md border"
            />
        </button>
    );
}

export default function GamesTiers({
    tiers,
    library,
}: {
    tiers: Record<GameTier, ListGame[]>;
    library: SteamGame[];
}) {
    const saved = useMemo(() => orderOf(tiers), [tiers]);
    const [pending, setPending] = useState<Order | null>(null);
    const order = pending ?? saved;
    const saves = useRef(0);

    const [selected, setSelected] = useState<number | null>(null);
    const [dragging, setDragging] = useState<number | null>(null);
    const [drop, setDrop] = useState<Drop | null>(null);
    const [query, setQuery] = useState('');
    const [withUnplayed, setWithUnplayed] = useState(false);
    const [poolSize, setPoolSize] = useState(POOL_PAGE);

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

    const placed = useMemo(
        () => new Set(TIERS.flatMap(({ tier }) => order[tier])),
        [order],
    );

    const pool = useMemo(() => {
        const needle = normalize(query.trim());

        return library.filter(
            (game) =>
                !placed.has(game.app_id) &&
                (withUnplayed || game.playtime_minutes > 0 || needle !== '') &&
                (needle === '' || normalize(game.name).includes(needle)),
        );
    }, [library, placed, query, withUnplayed]);

    const tiered = placed.size;
    const playedLeft = library.filter(
        (game) => game.playtime_minutes > 0 && !placed.has(game.app_id),
    ).length;

    function save(next: Order) {
        if (same(next, order)) {
            return;
        }

        const id = ++saves.current;
        setPending(next);
        router.put(
            games.tiers.update.url(),
            { tiers: next },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => {
                    // Only the latest save clears the optimistic order.
                    if (saves.current === id) {
                        setPending(null);
                    }
                },
            },
        );
    }

    function move(appId: number, tier: GameTier | null, before: number | null) {
        save(moved(order, appId, tier, before));
    }

    function tierOf(appId: number): GameTier | null {
        return (
            TIERS.find(({ tier }) => order[tier].includes(appId))?.tier ?? null
        );
    }

    /** Move the selected game one place left or right within its tier. */
    function shift(appId: number, delta: -1 | 1) {
        const tier = tierOf(appId);

        if (tier === null) {
            return;
        }

        const list = order[tier];
        const index = list.indexOf(appId);
        const target = index + delta;

        if (target < 0 || target >= list.length) {
            return;
        }

        move(
            appId,
            tier,
            delta < 0 ? list[target] : (list[target + 1] ?? null),
        );
    }

    function endDrag() {
        setDragging(null);
        setDrop(null);
    }

    function dropOn(event: DragEvent, tier: GameTier | null) {
        event.preventDefault();
        const appId =
            dragging ?? Number(event.dataTransfer.getData('text/plain'));

        if (appId) {
            move(appId, tier, tier === null ? null : (drop?.before ?? null));
        }

        endDrag();
    }

    /** Over a tile: land before it, or after it on its right half. */
    function overTile(
        event: DragEvent<HTMLButtonElement>,
        tier: GameTier | null,
        list: number[],
        index: number,
    ) {
        event.preventDefault();
        event.stopPropagation();

        if (tier === null) {
            setDrop({ tier: null, before: null });

            return;
        }

        const rect = event.currentTarget.getBoundingClientRect();
        const after = event.clientX > rect.left + rect.width / 2;
        let before: number | null = after
            ? (list[index + 1] ?? null)
            : list[index];

        if (before === dragging) {
            before = list[list.indexOf(before) + 1] ?? null;
        }

        setDrop({ tier, before });
    }

    const selectedGame =
        selected === null ? null : (byApp.get(selected) ?? null);
    const selectedTier = selected === null ? null : tierOf(selected);

    return (
        <>
            <Head title="Tier list" />
            <div className="flex flex-1 flex-col gap-6 p-4 pb-28">
                <PageHeader
                    title="Jogos"
                    description="Sua biblioteca da Steam: jogos, tempo de jogo, conquistas e estatísticas."
                />

                <GamesTabs />

                <p className="text-sm text-muted-foreground">
                    Arraste os jogos para os tiers, ou toque num jogo e depois
                    na letra do tier. {tiered}{' '}
                    {tiered === 1 ? 'jogo classificado' : 'jogos classificados'}
                    {playedLeft > 0 &&
                        ` · ${playedLeft} ${playedLeft === 1 ? 'jogado falta' : 'jogados faltam'}`}
                    .
                </p>

                <div className="overflow-hidden rounded-xl border">
                    {TIERS.map(({ tier, className }) => (
                        <div
                            key={tier}
                            className={cn(
                                'flex min-h-20 border-b last:border-b-0 sm:min-h-24',
                                drop?.tier === tier && 'bg-muted/60',
                            )}
                            onDragOver={(event) => {
                                event.preventDefault();
                                setDrop({ tier, before: null });
                            }}
                            onDrop={(event) => dropOn(event, tier)}
                        >
                            <button
                                type="button"
                                onClick={() =>
                                    selected !== null &&
                                    move(selected, tier, null)
                                }
                                disabled={selected === null}
                                aria-label={
                                    selected === null
                                        ? `Tier ${tier}`
                                        : `Colocar no tier ${tier}`
                                }
                                className={cn(
                                    'flex w-12 shrink-0 items-center justify-center text-2xl font-bold sm:w-16 sm:text-3xl',
                                    className,
                                    selected !== null &&
                                        'cursor-pointer hover:brightness-110',
                                )}
                            >
                                {tier}
                            </button>
                            <div className="flex flex-1 flex-wrap content-start items-start gap-2 p-2">
                                {order[tier].map((appId, index) => {
                                    const game = byApp.get(appId);

                                    return game ? (
                                        <Tile
                                            key={appId}
                                            game={game}
                                            selected={selected === appId}
                                            dropBefore={
                                                drop?.tier === tier &&
                                                drop.before === appId &&
                                                dragging !== appId
                                            }
                                            onSelect={() =>
                                                setSelected(
                                                    selected === appId
                                                        ? null
                                                        : appId,
                                                )
                                            }
                                            onDragStart={() =>
                                                setDragging(appId)
                                            }
                                            onDragOver={(event) =>
                                                overTile(
                                                    event,
                                                    tier,
                                                    order[tier],
                                                    index,
                                                )
                                            }
                                            onDragEnd={endDrag}
                                        />
                                    ) : null;
                                })}
                            </div>
                        </div>
                    ))}
                </div>

                <section
                    className={cn(
                        'space-y-3 rounded-xl border border-dashed p-3',
                        drop?.tier === null &&
                            dragging !== null &&
                            'bg-muted/60',
                    )}
                    onDragOver={(event) => {
                        event.preventDefault();
                        setDrop({ tier: null, before: null });
                    }}
                    onDrop={(event) => dropOn(event, null)}
                >
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <h2 className="text-sm font-medium">
                            Para classificar
                            <span className="ml-2 font-normal text-muted-foreground">
                                {pool.length}
                            </span>
                        </h2>
                        <div className="flex flex-wrap items-center gap-3">
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="tiers-unplayed"
                                    checked={withUnplayed}
                                    onCheckedChange={(value) => {
                                        setWithUnplayed(value === true);
                                        setPoolSize(POOL_PAGE);
                                    }}
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
                                    onChange={(event) => {
                                        setQuery(event.target.value);
                                        setPoolSize(POOL_PAGE);
                                    }}
                                />
                            </div>
                        </div>
                    </div>

                    {pool.length === 0 ? (
                        <p className="py-6 text-center text-sm text-muted-foreground">
                            {query.trim() !== ''
                                ? 'Nenhum jogo encontrado.'
                                : 'Todos os jogos que você jogou já estão na tier list.'}
                        </p>
                    ) : (
                        <>
                            <div className="flex flex-wrap gap-2">
                                {pool.slice(0, poolSize).map((game) => (
                                    <Tile
                                        key={game.app_id}
                                        game={{
                                            app_id: game.app_id,
                                            name: game.name,
                                            game_id: game.id,
                                            playtime_minutes:
                                                game.playtime_minutes,
                                            last_played_at: game.last_played_at,
                                        }}
                                        selected={selected === game.app_id}
                                        dropBefore={false}
                                        onSelect={() =>
                                            setSelected(
                                                selected === game.app_id
                                                    ? null
                                                    : game.app_id,
                                            )
                                        }
                                        onDragStart={() =>
                                            setDragging(game.app_id)
                                        }
                                        onDragOver={(event) =>
                                            overTile(event, null, [], 0)
                                        }
                                        onDragEnd={endDrag}
                                    />
                                ))}
                            </div>
                            {poolSize < pool.length && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        setPoolSize(poolSize + POOL_PAGE)
                                    }
                                >
                                    Mostrar mais ({pool.length - poolSize})
                                </Button>
                            )}
                        </>
                    )}
                </section>
            </div>

            {selectedGame && (
                <div className="fixed inset-x-0 bottom-0 z-40 border-t bg-background/95 p-3 shadow-lg backdrop-blur md:left-[var(--sidebar-width)]">
                    <div className="mx-auto flex max-w-4xl flex-wrap items-center gap-2">
                        <div className="flex min-w-0 flex-1 items-center gap-2">
                            <Crown
                                className="size-4 shrink-0 text-muted-foreground"
                                aria-hidden
                            />
                            <span className="truncate text-sm font-medium">
                                {selectedGame.name}
                            </span>
                        </div>
                        <div className="flex flex-wrap items-center gap-1">
                            {TIERS.map(({ tier, className }) => (
                                <button
                                    key={tier}
                                    type="button"
                                    onClick={() =>
                                        move(selectedGame.app_id, tier, null)
                                    }
                                    aria-pressed={selectedTier === tier}
                                    className={cn(
                                        'size-9 rounded-md text-sm font-bold',
                                        className,
                                        selectedTier === tier &&
                                            'ring-2 ring-foreground ring-offset-1 ring-offset-background',
                                    )}
                                >
                                    {tier}
                                </button>
                            ))}
                            {selectedTier !== null && (
                                <>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Mover para a esquerda"
                                        onClick={() =>
                                            shift(selectedGame.app_id, -1)
                                        }
                                    >
                                        <ArrowLeft />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Mover para a direita"
                                        onClick={() =>
                                            shift(selectedGame.app_id, 1)
                                        }
                                    >
                                        <ArrowRight />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => {
                                            move(
                                                selectedGame.app_id,
                                                null,
                                                null,
                                            );
                                            setSelected(null);
                                        }}
                                    >
                                        Tirar
                                    </Button>
                                </>
                            )}
                            {selectedGame.game_id !== null && (
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Abrir a página do jogo"
                                    asChild
                                >
                                    <Link
                                        href={games.show(selectedGame.game_id)}
                                    >
                                        <ExternalLink />
                                    </Link>
                                </Button>
                            )}
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Fechar"
                                onClick={() => setSelected(null)}
                            >
                                <X />
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}

GamesTiers.layout = {
    breadcrumbs: [
        { title: 'Jogos', href: games.index() },
        { title: 'Tier list', href: games.tiers() },
    ],
};
