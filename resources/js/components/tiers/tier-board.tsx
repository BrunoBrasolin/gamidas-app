import { Link, router } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Crown, ExternalLink, X } from 'lucide-react';
import type { DragEvent, ReactNode } from 'react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { TIERS } from '@/lib/tiers';
import { cn } from '@/lib/utils';
import type { Tier } from '@/types';

/** The ids in each tier, in order. */
export type TierOrder = Record<Tier, number[]>;

/** Something that can be ranked: a game or a show. */
export type TierItem = {
    name: string;
    /** The tooltip, when it says more than the name. */
    title?: string;
    /** The item's own page, when it has one. */
    href: string | null;
    art: ReactNode;
};

/** Where a dragged item would land: a tier (null = off the list) and the item it goes before. */
type Drop = { tier: Tier | null; before: number | null };

const POOL_PAGE = 60;

/** The order with an item moved into a tier (before another item, or last), or off the list. */
function moved(
    order: TierOrder,
    id: number,
    tier: Tier | null,
    before: number | null,
): TierOrder {
    const next = Object.fromEntries(
        TIERS.map(({ tier: key }) => [
            key,
            order[key].filter((other) => other !== id),
        ]),
    ) as TierOrder;

    if (tier !== null) {
        const list = next[tier];
        const at = before === null ? -1 : list.indexOf(before);
        list.splice(at < 0 ? list.length : at, 0, id);
    }

    return next;
}

function same(a: TierOrder, b: TierOrder): boolean {
    return TIERS.every(({ tier }) => a[tier].join(',') === b[tier].join(','));
}

function Tile({
    item,
    className,
    selected,
    dropBefore,
    onSelect,
    onDragStart,
    onDragOver,
    onDragEnd,
}: {
    item: TierItem;
    className: string;
    selected: boolean;
    dropBefore: boolean;
    onSelect: () => void;
    onDragStart: (event: DragEvent<HTMLButtonElement>) => void;
    onDragOver: (event: DragEvent<HTMLButtonElement>) => void;
    onDragEnd: () => void;
}) {
    return (
        <button
            type="button"
            draggable
            onClick={onSelect}
            onDragStart={onDragStart}
            onDragOver={onDragOver}
            onDragEnd={onDragEnd}
            title={item.title ?? item.name}
            aria-label={item.title ?? item.name}
            aria-pressed={selected}
            className={cn(
                'relative shrink-0 cursor-grab rounded-md transition-transform outline-none focus-visible:ring-2 focus-visible:ring-ring active:cursor-grabbing',
                className,
                selected &&
                    'ring-2 ring-primary ring-offset-2 ring-offset-background',
                dropBefore &&
                    'before:absolute before:inset-y-0 before:-left-1.5 before:w-1 before:rounded-full before:bg-primary',
            )}
        >
            <span className="pointer-events-none block">{item.art}</span>
        </button>
    );
}

/**
 * An editable tier list: items are dragged between tiers and the pool below,
 * or tapped and then placed with the bar that shows up at the bottom. Every
 * move saves the whole list.
 */
export function TierBoard({
    saved,
    saveUrl,
    item,
    tileClassName,
    summary,
    pool,
    poolControls,
    filterKey,
    emptyPool,
    openLabel,
}: {
    /** The list as the server has it. */
    saved: TierOrder;
    /** Where to PUT `{ tiers }`. */
    saveUrl: string;
    item: (id: number) => TierItem | null;
    /** The width of a tile. */
    tileClassName: string;
    /** The line above the list, given the items placed on it. */
    summary: (placed: Set<number>) => ReactNode;
    /** The items to offer below the list, given those already placed. */
    pool: (placed: Set<number>) => number[];
    /** Search and filters of the pool. */
    poolControls: ReactNode;
    /** Changes with the filters, to show the pool from its start again. */
    filterKey: string;
    emptyPool: string;
    /** Label of the button to the selected item's page. */
    openLabel: string;
}) {
    const [pending, setPending] = useState<TierOrder | null>(null);
    const order = pending ?? saved;
    const saves = useRef(0);

    const [selected, setSelected] = useState<number | null>(null);
    const [dragging, setDragging] = useState<number | null>(null);
    const [drop, setDrop] = useState<Drop | null>(null);
    const [shown, setShown] = useState({ key: filterKey, size: POOL_PAGE });
    const poolSize = shown.key === filterKey ? shown.size : POOL_PAGE;

    const placed = new Set(TIERS.flatMap(({ tier }) => order[tier]));
    const waiting = pool(placed);

    function save(next: TierOrder) {
        if (same(next, order)) {
            return;
        }

        const id = ++saves.current;
        setPending(next);
        router.put(
            saveUrl,
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

    function move(id: number, tier: Tier | null, before: number | null) {
        save(moved(order, id, tier, before));
    }

    function tierOf(id: number): Tier | null {
        return TIERS.find(({ tier }) => order[tier].includes(id))?.tier ?? null;
    }

    /** Move the selected item one place left or right within its tier. */
    function shift(id: number, delta: -1 | 1) {
        const tier = tierOf(id);

        if (tier === null) {
            return;
        }

        const list = order[tier];
        const index = list.indexOf(id);
        const target = index + delta;

        if (target < 0 || target >= list.length) {
            return;
        }

        move(id, tier, delta < 0 ? list[target] : (list[target + 1] ?? null));
    }

    function endDrag() {
        setDragging(null);
        setDrop(null);
    }

    function dropOn(event: DragEvent, tier: Tier | null) {
        event.preventDefault();
        const id = dragging ?? Number(event.dataTransfer.getData('text/plain'));

        if (id) {
            move(id, tier, tier === null ? null : (drop?.before ?? null));
        }

        endDrag();
    }

    /** Over a tile: land before it, or after it on its right half. */
    function overTile(
        event: DragEvent<HTMLButtonElement>,
        tier: Tier | null,
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

    function tile(id: number, tier: Tier | null, index: number) {
        const data = item(id);

        return data ? (
            <Tile
                key={id}
                item={data}
                className={tileClassName}
                selected={selected === id}
                dropBefore={
                    tier !== null &&
                    drop?.tier === tier &&
                    drop.before === id &&
                    dragging !== id
                }
                onSelect={() => setSelected(selected === id ? null : id)}
                onDragStart={(event) => {
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', String(id));
                    setDragging(id);
                }}
                onDragOver={(event) =>
                    overTile(
                        event,
                        tier,
                        tier === null ? [] : order[tier],
                        index,
                    )
                }
                onDragEnd={endDrag}
            />
        ) : null;
    }

    const selectedItem = selected === null ? null : item(selected);
    const selectedTier = selected === null ? null : tierOf(selected);

    return (
        <>
            <p className="text-sm text-muted-foreground">{summary(placed)}</p>

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
                                selected !== null && move(selected, tier, null)
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
                            {order[tier].map((id, index) =>
                                tile(id, tier, index),
                            )}
                        </div>
                    </div>
                ))}
            </div>

            <section
                className={cn(
                    'space-y-3 rounded-xl border border-dashed p-3',
                    drop?.tier === null && dragging !== null && 'bg-muted/60',
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
                            {waiting.length}
                        </span>
                    </h2>
                    <div className="flex flex-wrap items-center gap-3">
                        {poolControls}
                    </div>
                </div>

                {waiting.length === 0 ? (
                    <p className="py-6 text-center text-sm text-muted-foreground">
                        {emptyPool}
                    </p>
                ) : (
                    <>
                        <div className="flex flex-wrap gap-2">
                            {waiting
                                .slice(0, poolSize)
                                .map((id, index) => tile(id, null, index))}
                        </div>
                        {poolSize < waiting.length && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    setShown({
                                        key: filterKey,
                                        size: poolSize + POOL_PAGE,
                                    })
                                }
                            >
                                Mostrar mais ({waiting.length - poolSize})
                            </Button>
                        )}
                    </>
                )}
            </section>

            {selected !== null && selectedItem && (
                <div className="fixed inset-x-0 bottom-0 z-40 border-t bg-background/95 p-3 shadow-lg backdrop-blur md:left-[var(--sidebar-width)]">
                    <div className="mx-auto flex max-w-4xl flex-wrap items-center gap-2">
                        <div className="flex min-w-0 flex-1 items-center gap-2">
                            <Crown
                                className="size-4 shrink-0 text-muted-foreground"
                                aria-hidden
                            />
                            <span className="truncate text-sm font-medium">
                                {selectedItem.name}
                            </span>
                        </div>
                        <div className="flex flex-wrap items-center gap-1">
                            {TIERS.map(({ tier, className }) => (
                                <button
                                    key={tier}
                                    type="button"
                                    onClick={() => move(selected, tier, null)}
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
                                        onClick={() => shift(selected, -1)}
                                    >
                                        <ArrowLeft />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Mover para a direita"
                                        onClick={() => shift(selected, 1)}
                                    >
                                        <ArrowRight />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => {
                                            move(selected, null, null);
                                            setSelected(null);
                                        }}
                                    >
                                        Tirar
                                    </Button>
                                </>
                            )}
                            {selectedItem.href !== null && (
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label={openLabel}
                                    asChild
                                >
                                    <Link href={selectedItem.href}>
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
