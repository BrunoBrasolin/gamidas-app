import { Deferred, Head, Link } from '@inertiajs/react';
import { Gamepad2, Search, User, Users, Wifi } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/finance/empty-state';
import { PageHeader } from '@/components/finance/page-header';
import { StatCard } from '@/components/finance/stat-card';
import { GamesTabs } from '@/components/games/games-tabs';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { formatDateTime } from '@/lib/format';
import { formatDay, normalize, personaStatus } from '@/lib/games';
import { cn } from '@/lib/utils';
import games from '@/routes/games';
import type { SteamFriend, SteamFriendList } from '@/types';

/** Playing first, then online, then offline; by name within each. */
function rank(friend: SteamFriend): number {
    if (friend.playing) {
        return 0;
    }

    return friend.persona_state > 0 ? 1 : 2;
}

function FriendRow({ friend }: { friend: SteamFriend }) {
    const online = friend.persona_state > 0 || friend.playing !== null;

    return (
        <Card className="py-3">
            <CardContent className="flex items-center gap-3 px-4">
                <div className="relative shrink-0">
                    {friend.avatar_url ? (
                        <img
                            src={friend.avatar_url}
                            alt=""
                            loading="lazy"
                            className="size-11 rounded-md border object-cover"
                        />
                    ) : (
                        <div className="flex size-11 items-center justify-center rounded-md border bg-muted">
                            <User
                                className="size-5 text-muted-foreground"
                                aria-hidden
                            />
                        </div>
                    )}
                    <span
                        aria-hidden
                        className={cn(
                            'absolute -right-0.5 -bottom-0.5 size-3 rounded-full border-2 border-card',
                            friend.playing
                                ? 'bg-emerald-500'
                                : online
                                  ? 'bg-sky-500'
                                  : 'bg-muted-foreground/40',
                        )}
                    />
                </div>
                <div className="min-w-0 flex-1">
                    <a
                        href={friend.profile_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="block truncate font-medium hover:underline"
                    >
                        {friend.persona_name}
                    </a>
                    <p className="flex flex-wrap gap-x-3 text-xs text-muted-foreground">
                        {friend.playing && friend.my_game_id !== null ? (
                            <Link
                                href={games.show(friend.my_game_id)}
                                className="text-emerald-600 hover:underline dark:text-emerald-400"
                            >
                                {personaStatus(friend)}
                            </Link>
                        ) : (
                            <span
                                className={cn(
                                    friend.playing &&
                                        'text-emerald-600 dark:text-emerald-400',
                                )}
                            >
                                {personaStatus(friend)}
                            </span>
                        )}
                        {!online && friend.last_logoff_at && (
                            <span>
                                Visto em {formatDateTime(friend.last_logoff_at)}
                            </span>
                        )}
                    </p>
                </div>
                <div className="hidden shrink-0 text-right text-xs text-muted-foreground sm:block">
                    {friend.friend_since && (
                        <p>Amigos desde {formatDay(friend.friend_since)}</p>
                    )}
                    {friend.country_code && <p>{friend.country_code}</p>}
                </div>
            </CardContent>
        </Card>
    );
}

/** The friends with the totals, or why there are none to show. */
function FriendsList({ list }: { list: SteamFriendList }) {
    const { friends, private: isPrivate, error } = list;
    const [query, setQuery] = useState('');

    const sorted = useMemo(() => {
        const needle = normalize(query.trim());

        return friends
            .filter(
                (friend) =>
                    needle === '' ||
                    normalize(friend.persona_name).includes(needle) ||
                    normalize(friend.playing?.name ?? '').includes(needle),
            )
            .sort(
                (a, b) =>
                    rank(a) - rank(b) ||
                    a.persona_name.localeCompare(b.persona_name, 'pt-BR'),
            );
    }, [friends, query]);

    const online = friends.filter(
        (friend) => friend.persona_state > 0 || friend.playing !== null,
    ).length;
    const { playing, topGame } = useMemo(() => {
        const counts = new Map<string, number>();
        let total = 0;

        for (const friend of friends) {
            if (friend.playing) {
                total++;
                counts.set(
                    friend.playing.name,
                    (counts.get(friend.playing.name) ?? 0) + 1,
                );
            }
        }

        return {
            playing: total,
            topGame:
                [...counts.entries()].sort((a, b) => b[1] - a[1])[0] ?? null,
        };
    }, [friends]);

    return (
        <>
            {error ? (
                <Alert variant="destructive">
                    <AlertDescription>
                        Não foi possível ler os amigos agora: {error}
                    </AlertDescription>
                </Alert>
            ) : isPrivate ? (
                <EmptyState
                    icon={Users}
                    title="Lista de amigos privada"
                    description='Na Steam, em Perfil › Editar perfil › Configurações de privacidade, deixe "Lista de amigos" como Pública para vê-la aqui.'
                />
            ) : friends.length === 0 ? (
                <EmptyState
                    icon={Users}
                    title="Nenhum amigo na Steam"
                    description="Os amigos da sua conta Steam aparecem aqui."
                />
            ) : (
                <>
                    <div className="grid grid-cols-2 gap-3 lg:grid-cols-3">
                        <StatCard
                            title="Amigos"
                            value={friends.length}
                            icon={Users}
                        />
                        <StatCard
                            title="Online agora"
                            value={online}
                            icon={Wifi}
                        />
                        <StatCard
                            title="Jogando agora"
                            value={playing}
                            hint={
                                topGame && topGame[1] > 1
                                    ? `${topGame[1]} em ${topGame[0]}`
                                    : undefined
                            }
                            icon={Gamepad2}
                        />
                    </div>

                    <section className="space-y-4">
                        <div className="relative max-w-sm">
                            <Search
                                className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                aria-hidden
                            />
                            <Input
                                type="search"
                                placeholder="Buscar amigo ou jogo"
                                aria-label="Buscar amigo ou jogo"
                                className="pl-9"
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                            />
                        </div>
                        {sorted.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Ninguém encontrado.
                            </p>
                        ) : (
                            <ul className="grid gap-2 lg:grid-cols-2">
                                {sorted.map((friend) => (
                                    <li key={friend.steam_id}>
                                        <FriendRow friend={friend} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </>
            )}
        </>
    );
}

export default function GamesFriends({ list }: { list?: SteamFriendList }) {
    return (
        <>
            <Head title="Amigos na Steam" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Jogos"
                    description="Sua biblioteca da Steam: jogos, tempo de jogo, conquistas e estatísticas."
                />

                <GamesTabs />

                <Deferred
                    data="list"
                    fallback={
                        <div className="grid gap-2 lg:grid-cols-2" aria-hidden>
                            {Array.from({ length: 6 }, (_, index) => (
                                <Skeleton
                                    key={index}
                                    className="h-16 rounded-xl"
                                />
                            ))}
                        </div>
                    }
                >
                    {list && <FriendsList list={list} />}
                </Deferred>

                <p className="border-t pt-4 text-xs text-muted-foreground">
                    Lido da Steam agora (atualiza a cada 5 minutos).{' '}
                    <Badge variant="outline" className="ml-1 align-middle">
                        Só você vê esta página
                    </Badge>
                </p>
            </div>
        </>
    );
}

GamesFriends.layout = {
    breadcrumbs: [
        { title: 'Jogos', href: games.index() },
        { title: 'Amigos', href: games.friends() },
    ],
};
