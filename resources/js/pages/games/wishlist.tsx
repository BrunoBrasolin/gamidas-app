import { Head, Link } from '@inertiajs/react';
import {
    CalendarClock,
    ExternalLink,
    Gift,
    Percent,
    Search,
    ThumbsUp,
    Wallet,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/finance/empty-state';
import { PageHeader } from '@/components/finance/page-header';
import { StatCard } from '@/components/finance/stat-card';
import { GamesTabs } from '@/components/games/games-tabs';
import { GameImage } from '@/components/games/game-image';
import { SyncButton } from '@/components/games/steam-connection';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { formatMoney } from '@/lib/format';
import { formatDay, normalize } from '@/lib/games';
import games from '@/routes/games';
import type {
    SteamAccount,
    SteamWishlistItem,
    SteamWishlistSummary,
} from '@/types';

type Filter = 'all' | 'sale' | 'released' | 'coming';
type Sort = 'priority' | 'discount' | 'price' | 'added' | 'release' | 'reviews';

const FILTERS: Record<Filter, string> = {
    all: 'Todos',
    sale: 'Em promoção',
    released: 'Já lançados',
    coming: 'Em breve',
};

const SORTS: Record<Sort, string> = {
    priority: 'Minha ordem',
    discount: 'Maior desconto',
    price: 'Menor preço',
    added: 'Adicionados por último',
    release: 'Lançamento',
    reviews: 'Melhores análises',
};

function Price({ item }: { item: SteamWishlistItem }) {
    if (item.is_free) {
        return <span className="font-medium">Gratuito</span>;
    }

    if (!item.price_formatted) {
        return (
            <span className="text-sm text-muted-foreground">
                {item.coming_soon ? 'Ainda sem preço' : 'Indisponível'}
            </span>
        );
    }

    return (
        <span className="flex flex-wrap items-center justify-end gap-2">
            {item.discount_percent > 0 && (
                <Badge className="bg-emerald-600 text-white hover:bg-emerald-600">
                    −{item.discount_percent}%
                </Badge>
            )}
            {item.discount_percent > 0 && item.original_price_formatted && (
                <span className="text-xs text-muted-foreground line-through">
                    {item.original_price_formatted}
                </span>
            )}
            <span className="font-medium tabular-nums">
                {item.price_formatted}
            </span>
        </span>
    );
}

function WishlistRow({
    item,
    rank,
}: {
    item: SteamWishlistItem;
    rank: number | null;
}) {
    return (
        <Card className="overflow-hidden py-0">
            <CardContent className="flex flex-col gap-3 p-0 sm:flex-row sm:items-center">
                <GameImage
                    urls={[item.header_url]}
                    name={item.name}
                    className="aspect-[460/215] w-full shrink-0 sm:w-48"
                />
                <div className="min-w-0 flex-1 space-y-1 px-4 sm:px-0">
                    <div className="flex flex-wrap items-center gap-2">
                        {rank !== null && (
                            <span className="text-xs font-medium text-muted-foreground tabular-nums">
                                #{rank}
                            </span>
                        )}
                        <a
                            href={item.store_url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="truncate font-medium hover:underline"
                        >
                            {item.name}
                        </a>
                        {item.coming_soon && (
                            <Badge variant="outline">Em breve</Badge>
                        )}
                        {item.owned_game_id !== null && (
                            <Badge variant="secondary" asChild>
                                <Link href={games.show(item.owned_game_id)}>
                                    Na biblioteca
                                </Link>
                            </Badge>
                        )}
                    </div>
                    <p className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        {item.release_at && (
                            <span className="flex items-center gap-1.5">
                                <CalendarClock
                                    className="size-3.5"
                                    aria-hidden
                                />
                                {item.coming_soon ? 'Lança em ' : 'Lançado em '}
                                {formatDay(item.release_at)}
                            </span>
                        )}
                        {item.review_label && (
                            <span className="flex items-center gap-1.5">
                                <ThumbsUp className="size-3.5" aria-hidden />
                                {item.review_label}
                                {item.review_percent !== null &&
                                    ` (${item.review_percent}%)`}
                            </span>
                        )}
                        {item.added_at && (
                            <span>
                                Adicionado em {formatDay(item.added_at)}
                            </span>
                        )}
                    </p>
                </div>
                <div className="px-4 pb-3 sm:pb-0 sm:text-right">
                    <Price item={item} />
                </div>
            </CardContent>
        </Card>
    );
}

export default function GamesWishlist({
    account,
    summary,
    items,
}: {
    account: SteamAccount;
    summary: SteamWishlistSummary;
    items: SteamWishlistItem[];
}) {
    const [query, setQuery] = useState('');
    const [filter, setFilter] = useState<Filter>('all');
    const [sort, setSort] = useState<Sort>('priority');

    const shown = useMemo(() => {
        const needle = normalize(query.trim());
        const matches = items.filter((item) => {
            if (needle !== '' && !normalize(item.name).includes(needle)) {
                return false;
            }

            switch (filter) {
                case 'sale':
                    return item.discount_percent > 0;
                case 'released':
                    return !item.coming_soon;
                case 'coming':
                    return item.coming_soon;
                default:
                    return true;
            }
        });

        if (sort === 'priority') {
            return matches;
        }

        const price = (item: SteamWishlistItem) =>
            item.is_free ? 0 : (item.price_cents ?? Number.MAX_SAFE_INTEGER);

        return [...matches].sort((a, b) => {
            switch (sort) {
                case 'discount':
                    return b.discount_percent - a.discount_percent;
                case 'price':
                    return price(a) - price(b);
                case 'added':
                    return (b.added_at ?? '').localeCompare(a.added_at ?? '');
                case 'release':
                    return (a.release_at ?? '9999').localeCompare(
                        b.release_at ?? '9999',
                    );
                default:
                    return (b.review_percent ?? -1) - (a.review_percent ?? -1);
            }
        });
    }, [items, query, filter, sort]);

    return (
        <>
            <Head title="Lista de desejos" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Jogos"
                    description="Sua biblioteca da Steam: jogos, tempo de jogo, conquistas e estatísticas."
                >
                    <SyncButton />
                </PageHeader>

                <GamesTabs />

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <StatCard
                        title="Na lista"
                        value={summary.items}
                        hint={`${summary.coming_soon} ainda não ${summary.coming_soon === 1 ? 'lançado' : 'lançados'}`}
                        icon={Gift}
                    />
                    <StatCard
                        title="Em promoção"
                        value={summary.on_sale}
                        hint="Preços da última sincronização"
                        icon={Percent}
                    />
                    {summary.total_cents !== null && (
                        <StatCard
                            title="Tudo junto"
                            value={formatMoney(summary.total_cents)}
                            hint={
                                summary.free > 0
                                    ? `Sem contar ${summary.free} ${summary.free === 1 ? 'gratuito' : 'gratuitos'}`
                                    : 'Pelos preços de hoje'
                            }
                            icon={Wallet}
                        />
                    )}
                    {summary.savings_cents !== null && (
                        <StatCard
                            title="Economia nas promoções"
                            value={formatMoney(summary.savings_cents)}
                            hint="Comprando tudo o que está em promoção"
                            icon={Percent}
                        />
                    )}
                </div>

                {items.length === 0 ? (
                    <EmptyState
                        icon={Gift}
                        title="Lista de desejos vazia"
                        description={
                            account.public_games
                                ? 'Os jogos que você colocar na lista de desejos da Steam aparecem aqui depois da próxima sincronização.'
                                : 'A lista de desejos só aparece com os detalhes de jogo do perfil públicos na Steam.'
                        }
                    />
                ) : (
                    <section className="space-y-4">
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <div className="relative min-w-0 flex-1 sm:max-w-sm">
                                <Search
                                    className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                    aria-hidden
                                />
                                <Input
                                    type="search"
                                    placeholder="Buscar jogo"
                                    aria-label="Buscar na lista de desejos"
                                    className="pl-9"
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                />
                            </div>
                            <NativeSelect
                                aria-label="Filtrar"
                                className="sm:w-48"
                                value={filter}
                                onChange={(event) =>
                                    setFilter(event.target.value as Filter)
                                }
                            >
                                {Object.entries(FILTERS).map(
                                    ([value, label]) => (
                                        <option key={value} value={value}>
                                            {label}
                                        </option>
                                    ),
                                )}
                            </NativeSelect>
                            <NativeSelect
                                aria-label="Ordenar"
                                className="sm:w-48"
                                value={sort}
                                onChange={(event) =>
                                    setSort(event.target.value as Sort)
                                }
                            >
                                {Object.entries(SORTS).map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>

                        {shown.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nenhum jogo encontrado.
                            </p>
                        ) : (
                            <ul className="grid gap-2">
                                {shown.map((item) => (
                                    <li key={item.id}>
                                        <WishlistRow
                                            item={item}
                                            rank={
                                                sort === 'priority' &&
                                                item.priority > 0
                                                    ? item.priority
                                                    : null
                                            }
                                        />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}

                <p className="border-t pt-4 text-xs text-muted-foreground">
                    Preços e análises da loja Steam na última sincronização. O
                    Gamidas não é afiliado à Valve.
                    <a
                        href={`https://store.steampowered.com/wishlist/profiles/${account.steam_id}/`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="ml-2 inline-flex items-center gap-1 hover:underline"
                    >
                        <ExternalLink className="size-3" aria-hidden />
                        Ver na Steam
                    </a>
                </p>
            </div>
        </>
    );
}

GamesWishlist.layout = {
    breadcrumbs: [
        { title: 'Jogos', href: games.index() },
        { title: 'Lista de desejos', href: games.wishlist() },
    ],
};
