import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    CheckCircle2,
    Gamepad2,
    ListTodo,
    Play,
    Plus,
    RotateCcw,
    Search,
    Trash2,
    Trophy,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { ConfirmDialog } from '@/components/finance/confirm-dialog';
import { EmptyState } from '@/components/finance/empty-state';
import { PageHeader } from '@/components/finance/page-header';
import { StatCard } from '@/components/finance/stat-card';
import { GameImage } from '@/components/games/game-image';
import { GamesTabs } from '@/components/games/games-tabs';
import { TierBadge } from '@/components/games/tier-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDay, formatPlaytime, headerUrl, normalize } from '@/lib/games';
import games from '@/routes/games';
import type { BacklogEntry, BacklogStatus, SteamGame } from '@/types';

const PICKER_RESULTS = 40;

function setStatus(entry: BacklogEntry, status: BacklogStatus) {
    router.put(
        games.backlog.update.url(entry.id),
        { status },
        { preserveScroll: true },
    );
}

/** Minutes played since the game entered the list, when Steam still has it. */
function playedSince(entry: BacklogEntry): number | null {
    const now =
        entry.status === 'completed'
            ? entry.playtime_at_completion
            : entry.playtime_minutes;

    return now === null ? null : Math.max(0, now - entry.playtime_at_add);
}

function Title({ entry }: { entry: BacklogEntry }) {
    return entry.game_id !== null ? (
        <Link
            href={games.show(entry.game_id)}
            className="truncate font-medium hover:underline"
        >
            {entry.name}
        </Link>
    ) : (
        <span className="truncate font-medium">{entry.name}</span>
    );
}

function RemoveButton({ entry }: { entry: BacklogEntry }) {
    return (
        <ConfirmDialog
            trigger={
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label={`Tirar ${entry.name} da lista`}
                >
                    <Trash2 />
                </Button>
            }
            title="Tirar da lista?"
            description={`“${entry.name}” sai da lista para zerar. Dá para colocar de novo depois.`}
            confirmLabel="Tirar"
            url={games.backlog.destroy.url(entry.id)}
        />
    );
}

function PlayingCard({ entry }: { entry: BacklogEntry }) {
    const since = playedSince(entry);

    return (
        <Card className="overflow-hidden py-0">
            <CardContent className="flex flex-col gap-3 p-0 sm:flex-row sm:items-center">
                <GameImage
                    urls={[headerUrl(entry.app_id)]}
                    name={entry.name}
                    className="aspect-[460/215] w-full shrink-0 sm:w-52"
                />
                <div className="min-w-0 flex-1 space-y-1 px-4 sm:px-0">
                    <Title entry={entry} />
                    <p className="text-xs text-muted-foreground">
                        {entry.started_at &&
                            `Jogando desde ${formatDay(entry.started_at)}`}
                        {since !== null &&
                            since > 0 &&
                            ` · ${formatPlaytime(since)} desde que entrou na lista`}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2 px-4 pb-3 sm:pr-4 sm:pb-0 sm:pl-0">
                    <Button
                        size="sm"
                        onClick={() => setStatus(entry, 'completed')}
                    >
                        <Trophy />
                        Zerei
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setStatus(entry, 'queued')}
                    >
                        Voltar para a fila
                    </Button>
                    <RemoveButton entry={entry} />
                </div>
            </CardContent>
        </Card>
    );
}

function QueueRow({
    entry,
    index,
    count,
    onMove,
}: {
    entry: BacklogEntry;
    index: number;
    count: number;
    onMove: (delta: -1 | 1) => void;
}) {
    return (
        <Card className="py-2">
            <CardContent className="flex items-center gap-3 px-3">
                <span className="w-6 shrink-0 text-center text-sm font-medium text-muted-foreground tabular-nums">
                    {index + 1}
                </span>
                <GameImage
                    urls={[headerUrl(entry.app_id)]}
                    name={entry.name}
                    className="hidden aspect-[460/215] w-24 shrink-0 rounded sm:block"
                />
                <div className="flex min-w-0 flex-1 flex-col">
                    <Title entry={entry} />
                    <span className="text-xs text-muted-foreground">
                        {entry.playtime_minutes
                            ? `${formatPlaytime(entry.playtime_minutes)} jogadas`
                            : 'Nunca jogado'}{' '}
                        · na lista desde {formatDay(entry.added_at)}
                    </span>
                </div>
                <div className="flex shrink-0 items-center">
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Subir na fila"
                        disabled={index === 0}
                        onClick={() => onMove(-1)}
                    >
                        <ArrowUp />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Descer na fila"
                        disabled={index === count - 1}
                        onClick={() => onMove(1)}
                    >
                        <ArrowDown />
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        className="ml-1"
                        onClick={() => setStatus(entry, 'playing')}
                    >
                        <Play />
                        <span className="hidden sm:inline">Comecei</span>
                    </Button>
                    <RemoveButton entry={entry} />
                </div>
            </CardContent>
        </Card>
    );
}

function CompletedRow({ entry }: { entry: BacklogEntry }) {
    const since = playedSince(entry);

    return (
        <li className="flex items-center gap-3 py-2">
            <CheckCircle2
                className="size-4 shrink-0 text-emerald-600 dark:text-emerald-400"
                aria-hidden
            />
            <div className="flex min-w-0 flex-1 flex-col">
                <Title entry={entry} />
                <span className="text-xs text-muted-foreground">
                    {entry.completed_at &&
                        `Zerado em ${formatDay(entry.completed_at)}`}
                    {since !== null &&
                        since > 0 &&
                        ` · ${formatPlaytime(since)} desde que entrou na lista`}
                </span>
            </div>
            {entry.tier ? (
                <TierBadge tier={entry.tier} />
            ) : (
                <Link
                    href={games.tiers()}
                    className="hidden text-xs text-muted-foreground hover:underline sm:inline"
                >
                    Pôr na tier list
                </Link>
            )}
            <Button
                variant="ghost"
                size="icon"
                aria-label="Voltar para a fila"
                title="Jogar de novo (volta para a fila)"
                onClick={() => setStatus(entry, 'queued')}
            >
                <RotateCcw />
            </Button>
            <RemoveButton entry={entry} />
        </li>
    );
}

/** Pick a game of the library to add to the queue. */
function AddGameDialog({
    library,
    listed,
}: {
    library: SteamGame[];
    listed: Set<number>;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');

    const results = useMemo(() => {
        const needle = normalize(query.trim());

        return library
            .filter(
                (game) =>
                    !listed.has(game.app_id) &&
                    (needle === '' || normalize(game.name).includes(needle)),
            )
            .slice(0, PICKER_RESULTS);
    }, [library, listed, query]);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>
                    <Plus />
                    Adicionar jogo
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Adicionar à lista para zerar</DialogTitle>
                    <DialogDescription>
                        O jogo entra no fim da fila. Os mais jogados aparecem
                        primeiro.
                    </DialogDescription>
                </DialogHeader>
                <div className="relative">
                    <Search
                        className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden
                    />
                    <Input
                        type="search"
                        autoFocus
                        placeholder="Buscar na sua biblioteca"
                        aria-label="Buscar na sua biblioteca"
                        className="pl-9"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                    />
                </div>
                <ul className="-mx-2 max-h-80 overflow-y-auto">
                    {results.length === 0 ? (
                        <li className="px-2 py-6 text-center text-sm text-muted-foreground">
                            Nenhum jogo encontrado.
                        </li>
                    ) : (
                        results.map((game) => (
                            <li key={game.id}>
                                <button
                                    type="button"
                                    className="flex w-full items-center gap-3 rounded-md px-2 py-1.5 text-left hover:bg-muted"
                                    onClick={() =>
                                        router.post(
                                            games.backlog.store.url(game.id),
                                            {},
                                            {
                                                preserveScroll: true,
                                                onSuccess: () => setOpen(false),
                                            },
                                        )
                                    }
                                >
                                    <GameImage
                                        urls={[headerUrl(game.app_id)]}
                                        name={game.name}
                                        className="aspect-[460/215] w-20 shrink-0 rounded"
                                    />
                                    <span className="min-w-0 flex-1 truncate text-sm">
                                        {game.name}
                                    </span>
                                    <span className="shrink-0 text-xs text-muted-foreground">
                                        {game.playtime_minutes > 0
                                            ? formatPlaytime(
                                                  game.playtime_minutes,
                                              )
                                            : 'Nunca jogado'}
                                    </span>
                                </button>
                            </li>
                        ))
                    )}
                </ul>
            </DialogContent>
        </Dialog>
    );
}

export default function GamesBacklog({
    entries,
    library,
}: {
    entries: BacklogEntry[];
    library: SteamGame[];
}) {
    const playing = entries.filter((entry) => entry.status === 'playing');
    const queue = entries.filter((entry) => entry.status === 'queued');
    const completed = entries.filter((entry) => entry.status === 'completed');
    const listed = useMemo(
        () => new Set(entries.map((entry) => entry.app_id)),
        [entries],
    );
    const year = String(new Date().getFullYear());
    const completedThisYear = completed.filter((entry) =>
        entry.completed_at?.startsWith(year),
    ).length;

    function moveInQueue(index: number, delta: -1 | 1) {
        const ids = queue.map((entry) => entry.id);
        const [id] = ids.splice(index, 1);
        ids.splice(index + delta, 0, id);

        router.put(
            games.backlog.order.url(),
            { ids },
            { preserveScroll: true, preserveState: true },
        );
    }

    return (
        <>
            <Head title="Para zerar" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Jogos"
                    description="Sua biblioteca da Steam: jogos, tempo de jogo, conquistas e estatísticas."
                >
                    <AddGameDialog library={library} listed={listed} />
                </PageHeader>

                <GamesTabs />

                <div className="grid grid-cols-3 gap-3">
                    <StatCard
                        title="Na fila"
                        value={queue.length}
                        icon={ListTodo}
                    />
                    <StatCard
                        title="Jogando"
                        value={playing.length}
                        icon={Gamepad2}
                    />
                    <StatCard
                        title="Zerados"
                        value={completed.length}
                        hint={`${completedThisYear} em ${year}`}
                        icon={Trophy}
                    />
                </div>

                {entries.length === 0 ? (
                    <EmptyState
                        icon={ListTodo}
                        title="Nenhum jogo para zerar"
                        description='Adicione os jogos que você quer terminar. Também dá para adicionar pela página de cada jogo, em "Quero zerar".'
                    />
                ) : (
                    <>
                        {playing.length > 0 && (
                            <section className="space-y-3">
                                <h2 className="text-sm font-medium text-muted-foreground">
                                    Jogando agora
                                </h2>
                                <ul className="grid gap-2">
                                    {playing.map((entry) => (
                                        <li key={entry.id}>
                                            <PlayingCard entry={entry} />
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}

                        <section className="space-y-3">
                            <h2 className="text-sm font-medium text-muted-foreground">
                                Na fila
                            </h2>
                            {queue.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    A fila está vazia.
                                </p>
                            ) : (
                                <ol className="grid gap-2">
                                    {queue.map((entry, index) => (
                                        <li key={entry.id}>
                                            <QueueRow
                                                entry={entry}
                                                index={index}
                                                count={queue.length}
                                                onMove={(delta) =>
                                                    moveInQueue(index, delta)
                                                }
                                            />
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </section>

                        {completed.length > 0 && (
                            <section className="space-y-1">
                                <h2 className="text-sm font-medium text-muted-foreground">
                                    Zerados
                                </h2>
                                <ul className="divide-y">
                                    {completed.map((entry) => (
                                        <CompletedRow
                                            key={entry.id}
                                            entry={entry}
                                        />
                                    ))}
                                </ul>
                            </section>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

GamesBacklog.layout = {
    breadcrumbs: [
        { title: 'Jogos', href: games.index() },
        { title: 'Para zerar', href: games.backlog() },
    ],
};
