import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Award,
    Clock,
    Gamepad2,
    Library,
    Search,
    Settings2,
    Trophy,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@/components/finance/empty-state';
import { PageHeader } from '@/components/finance/page-header';
import { StatCard } from '@/components/finance/stat-card';
import { BarChart, MeterRow } from '@/components/games/bar-chart';
import { GameImage } from '@/components/games/game-image';
import {
    SteamAccountCard,
    SteamConnectCard,
    SyncButton,
} from '@/components/games/steam-connection';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import {
    capitalize,
    formatDate,
    formatDateTime,
    formatMonth,
    formatPercent,
    formatShortDate,
    formatShortMonth,
} from '@/lib/format';
import {
    completion,
    formatDay,
    formatPlaytime,
    formatShare,
    normalize,
    personaStatus,
    yearsSince,
} from '@/lib/games';
import { weekday } from '@/lib/time';
import { cn } from '@/lib/utils';
import games from '@/routes/games';
import type {
    SteamAccount,
    SteamAchievement,
    SteamDay,
    SteamGame,
    SteamMonth,
    SteamSummary,
} from '@/types';

const PAGE_SIZE = 48;

type Filter = 'all' | 'played' | 'unplayed' | 'achievements' | 'perfect';
type Sort = 'playtime' | 'recent' | 'name' | 'completion';

const FILTERS: Record<Filter, string> = {
    all: 'Todos',
    played: 'Jogados',
    unplayed: 'Nunca jogados',
    achievements: 'Com conquistas',
    perfect: '100% das conquistas',
};

const SORTS: Record<Sort, string> = {
    playtime: 'Mais jogados',
    recent: 'Jogados por último',
    name: 'Nome',
    completion: 'Conquistas (%)',
};

function Profile({ account }: { account: SteamAccount }) {
    const online = account.persona_state > 0 || account.playing !== null;
    const levelStart = account.xp_level_start;
    const toNext = account.xp_to_next_level;
    const levelProgress =
        account.xp !== null && levelStart !== null && toNext !== null
            ? ((account.xp - levelStart) /
                  Math.max(1, account.xp - levelStart + toNext)) *
              100
            : null;

    return (
        <Card className="py-4">
            <CardContent className="flex flex-col gap-4 px-4 sm:flex-row sm:items-center">
                {account.avatar_url ? (
                    <img
                        src={account.avatar_url}
                        alt=""
                        className="size-20 shrink-0 rounded-lg border object-cover"
                    />
                ) : (
                    <div className="flex size-20 shrink-0 items-center justify-center rounded-lg border bg-muted text-muted-foreground">
                        <Gamepad2 className="size-8" aria-hidden />
                    </div>
                )}
                <div className="min-w-0 flex-1 space-y-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <a
                            href={account.profile_url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="truncate text-lg font-semibold hover:underline"
                        >
                            {account.persona_name ?? account.steam_id}
                        </a>
                        {account.level !== null && (
                            <Badge
                                variant="outline"
                                className="rounded-full font-mono"
                                title="Nível na Steam"
                            >
                                Nível {account.level}
                            </Badge>
                        )}
                        <Badge
                            variant={online ? 'default' : 'secondary'}
                            className={cn(
                                account.playing &&
                                    'bg-emerald-600 text-white hover:bg-emerald-600',
                            )}
                        >
                            {personaStatus(account)}
                        </Badge>
                    </div>
                    <p className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
                        {account.account_created_at && (
                            <span>
                                Na Steam desde{' '}
                                {formatDay(account.account_created_at)} (
                                {yearsSince(account.account_created_at)}{' '}
                                {yearsSince(account.account_created_at) === 1
                                    ? 'ano'
                                    : 'anos'}
                                )
                            </span>
                        )}
                        {account.badges_count !== null && (
                            <span>
                                {account.badges_count}{' '}
                                {account.badges_count === 1
                                    ? 'insígnia'
                                    : 'insígnias'}
                            </span>
                        )}
                        {account.xp !== null && (
                            <span>{account.xp.toLocaleString('pt-BR')} XP</span>
                        )}
                        {account.country_code && (
                            <span>{account.country_code}</span>
                        )}
                    </p>
                    {levelProgress !== null && toNext !== null && (
                        <div className="max-w-sm space-y-1">
                            <div
                                className="h-1.5 overflow-hidden rounded-full bg-muted"
                                role="progressbar"
                                aria-label="Progresso até o próximo nível"
                                aria-valuemin={0}
                                aria-valuemax={100}
                                aria-valuenow={Math.round(levelProgress)}
                            >
                                <div
                                    className="h-full rounded-full bg-primary"
                                    style={{ width: `${levelProgress}%` }}
                                />
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Faltam {toNext.toLocaleString('pt-BR')} XP para
                                o nível {(account.level ?? 0) + 1}
                            </p>
                        </div>
                    )}
                </div>
                <p className="text-xs text-muted-foreground sm:self-end">
                    {account.checked_at
                        ? `Atualizado em ${formatDateTime(account.checked_at)}`
                        : 'Ainda não sincronizado'}
                </p>
            </CardContent>
        </Card>
    );
}

function RecentlyPlayed({ games: recent }: { games: SteamGame[] }) {
    return (
        <section className="space-y-3">
            <h2 className="text-sm font-medium text-muted-foreground">
                Jogados nas últimas 2 semanas
            </h2>
            <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                {recent.slice(0, 8).map((game) => (
                    <li key={game.id}>
                        <Link
                            href={games.show(game.id)}
                            className="group block overflow-hidden rounded-xl border bg-card transition-colors hover:border-primary/50"
                        >
                            <GameImage
                                urls={[game.header_url, game.icon_url]}
                                name={game.name}
                                className="aspect-[460/215] w-full"
                            />
                            <div className="space-y-0.5 p-3">
                                <p className="truncate text-sm font-medium group-hover:underline">
                                    {game.name}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {formatPlaytime(
                                        game.playtime_2weeks_minutes,
                                    )}{' '}
                                    nas 2 semanas ·{' '}
                                    {formatPlaytime(game.playtime_minutes)} no
                                    total
                                </p>
                            </div>
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}

function History({
    months,
    days,
    since,
}: {
    months: SteamMonth[];
    days: SteamDay[];
    since: string | null;
}) {
    const description = since
        ? `Registrado pelo Gamidas desde ${formatDate(since)}: a Steam só informa o total, então o tempo de cada dia é a diferença entre duas sincronizações.`
        : 'A Steam só informa o tempo total de cada jogo, então o histórico por dia começa a ser registrado a partir da primeira sincronização.';

    return (
        <div className="grid gap-4 xl:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>Tempo por mês</CardTitle>
                    <CardDescription>{description}</CardDescription>
                </CardHeader>
                <CardContent>
                    <BarChart
                        bars={months.map((month) => ({
                            key: month.month,
                            label: formatShortMonth(month.month),
                            value: month.minutes,
                            title: `${capitalize(formatMonth(month.month))}: ${formatPlaytime(month.minutes)}`,
                        }))}
                    />
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle>Últimos 30 dias</CardTitle>
                    <CardDescription>
                        {formatPlaytime(
                            days.reduce((sum, day) => sum + day.minutes, 0),
                        )}{' '}
                        no período ·{' '}
                        {days.filter((day) => day.minutes > 0).length} dias com
                        jogo
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <BarChart
                        bars={days.map((day, index) => ({
                            key: day.date,
                            label:
                                index % 5 === 4
                                    ? formatShortDate(day.date)
                                    : undefined,
                            value: day.minutes,
                            title: `${weekday(day.date)}, ${formatDate(day.date)}: ${formatPlaytime(day.minutes)}`,
                        }))}
                    />
                </CardContent>
            </Card>
        </div>
    );
}

function TopAndPlatforms({
    top,
    summary,
}: {
    top: SteamGame[];
    summary: SteamSummary;
}) {
    const platforms = [
        { key: 'windows', label: 'Windows' },
        { key: 'linux', label: 'Linux' },
        { key: 'deck', label: 'Steam Deck' },
        { key: 'mac', label: 'macOS' },
    ] as const;
    const recorded = platforms.some(
        (platform) => summary.platforms[platform.key] > 0,
    );

    return (
        <div className="grid gap-4 xl:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>Mais jogados</CardTitle>
                </CardHeader>
                <CardContent>
                    <ul className="space-y-3">
                        {top.map((game) => (
                            <MeterRow
                                key={game.id}
                                label={game.name}
                                value={game.playtime_minutes}
                                max={top[0]?.playtime_minutes ?? 1}
                                display={formatPlaytime(game.playtime_minutes)}
                                href={games.show.url(game.id)}
                            />
                        ))}
                    </ul>
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle>Por plataforma</CardTitle>
                    <CardDescription>
                        Parte do tempo total jogada em cada sistema. Horas
                        antigas podem não ter a plataforma registrada.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {recorded ? (
                        <ul className="space-y-3">
                            {platforms.map((platform) => (
                                <MeterRow
                                    key={platform.key}
                                    label={platform.label}
                                    value={summary.platforms[platform.key]}
                                    max={summary.playtime_minutes}
                                    display={formatPlaytime(
                                        summary.platforms[platform.key],
                                    )}
                                />
                            ))}
                        </ul>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Sem tempo por plataforma registrado.
                        </p>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}

function RecentAchievements({
    achievements,
}: {
    achievements: SteamAchievement[];
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Conquistas recentes</CardTitle>
            </CardHeader>
            <CardContent>
                <ul className="grid gap-3 md:grid-cols-2">
                    {achievements.map((achievement) => (
                        <li
                            key={achievement.id}
                            className="flex items-center gap-3"
                        >
                            {achievement.icon_url ? (
                                <img
                                    src={achievement.icon_url}
                                    alt=""
                                    loading="lazy"
                                    className="size-10 shrink-0 rounded-md border"
                                />
                            ) : (
                                <div className="flex size-10 shrink-0 items-center justify-center rounded-md border bg-muted">
                                    <Trophy
                                        className="size-4 text-muted-foreground"
                                        aria-hidden
                                    />
                                </div>
                            )}
                            <div className="min-w-0">
                                <p className="truncate text-sm font-medium">
                                    {achievement.name}
                                </p>
                                <p className="truncate text-xs text-muted-foreground">
                                    {achievement.game && (
                                        <Link
                                            href={games.show(
                                                achievement.game.id,
                                            )}
                                            className="hover:underline"
                                        >
                                            {achievement.game.name}
                                        </Link>
                                    )}
                                    {achievement.unlocked_at &&
                                        ` · ${formatDateTime(achievement.unlocked_at)}`}
                                    {achievement.global_percent_bp !== null &&
                                        ` · ${formatShare(achievement.global_percent_bp)} dos jogadores`}
                                </p>
                            </div>
                        </li>
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}

function GameTile({ game }: { game: SteamGame }) {
    const percent = completion(game);

    return (
        <Link
            href={games.show(game.id)}
            className="group block space-y-1.5"
            title={game.name}
        >
            <GameImage
                urls={[game.cover_url, game.header_url, game.icon_url]}
                name={game.name}
                className="aspect-[2/3] w-full rounded-lg border transition-transform group-hover:-translate-y-0.5 group-hover:shadow-md"
            />
            <p className="truncate text-sm font-medium group-hover:underline">
                {game.name}
            </p>
            <p className="text-xs text-muted-foreground">
                {game.playtime_minutes > 0
                    ? formatPlaytime(game.playtime_minutes)
                    : 'Nunca jogado'}
                {percent !== null &&
                    ` · ${game.achievements_unlocked}/${game.achievements_total}`}
            </p>
            {percent !== null && (
                <div className="h-1 overflow-hidden rounded-full bg-muted">
                    <div
                        className={cn(
                            'h-full rounded-full',
                            percent >= 100 ? 'bg-amber-500' : 'bg-primary',
                        )}
                        style={{ width: `${percent}%` }}
                    />
                </div>
            )}
        </Link>
    );
}

function LibraryGrid({ games: library }: { games: SteamGame[] }) {
    const [query, setQuery] = useState('');
    const [filter, setFilter] = useState<Filter>('all');
    const [sort, setSort] = useState<Sort>('playtime');
    const [visible, setVisible] = useState(PAGE_SIZE);

    const shown = useMemo(() => {
        const needle = normalize(query.trim());
        const matches = library.filter((game) => {
            if (needle !== '' && !normalize(game.name).includes(needle)) {
                return false;
            }

            switch (filter) {
                case 'played':
                    return game.playtime_minutes > 0;
                case 'unplayed':
                    return game.playtime_minutes === 0;
                case 'achievements':
                    return (game.achievements_total ?? 0) > 0;
                case 'perfect':
                    return completion(game) === 100;
                default:
                    return true;
            }
        });

        const byName = (a: SteamGame, b: SteamGame) =>
            a.name.localeCompare(b.name, 'pt-BR');

        return [...matches].sort((a, b) => {
            switch (sort) {
                case 'name':
                    return byName(a, b);
                case 'recent':
                    return (
                        (b.last_played_at ?? '').localeCompare(
                            a.last_played_at ?? '',
                        ) || byName(a, b)
                    );
                case 'completion':
                    return (
                        (completion(b) ?? -1) - (completion(a) ?? -1) ||
                        byName(a, b)
                    );
                default:
                    return (
                        b.playtime_minutes - a.playtime_minutes || byName(a, b)
                    );
            }
        });
    }, [library, query, filter, sort]);

    return (
        <section className="space-y-4">
            <div className="flex flex-col gap-3">
                <h2 className="flex items-center gap-2 font-semibold">
                    <Library className="size-4" aria-hidden />
                    Biblioteca
                    <span className="text-sm font-normal text-muted-foreground">
                        {shown.length === library.length
                            ? library.length
                            : `${shown.length} de ${library.length}`}
                    </span>
                </h2>
                <div className="flex flex-col gap-2 sm:flex-row">
                    <div className="relative min-w-0 flex-1 sm:max-w-sm">
                        <Search
                            className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden
                        />
                        <Input
                            type="search"
                            placeholder="Buscar jogo"
                            aria-label="Buscar jogo"
                            className="pl-9"
                            value={query}
                            onChange={(event) => {
                                setQuery(event.target.value);
                                setVisible(PAGE_SIZE);
                            }}
                        />
                    </div>
                    <NativeSelect
                        aria-label="Filtrar"
                        className="sm:w-48"
                        value={filter}
                        onChange={(event) => {
                            setFilter(event.target.value as Filter);
                            setVisible(PAGE_SIZE);
                        }}
                    >
                        {Object.entries(FILTERS).map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
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
            </div>

            {shown.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Nenhum jogo encontrado.
                </p>
            ) : (
                <>
                    <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 2xl:grid-cols-8">
                        {shown.slice(0, visible).map((game) => (
                            <li key={game.id} className="min-w-0">
                                <GameTile game={game} />
                            </li>
                        ))}
                    </ul>
                    {visible < shown.length && (
                        <div className="flex justify-center">
                            <Button
                                variant="outline"
                                onClick={() => setVisible(visible + PAGE_SIZE)}
                            >
                                Mostrar mais ({shown.length - visible})
                            </Button>
                        </div>
                    )}
                </>
            )}
        </section>
    );
}

type ConnectedProps = {
    summary: SteamSummary;
    games: SteamGame[];
    recent: SteamGame[];
    top: SteamGame[];
    months: SteamMonth[];
    days: SteamDay[];
    history_since: string | null;
    recent_achievements: SteamAchievement[];
};

export default function GamesIndex({
    configured,
    account,
    ...props
}: {
    configured: boolean;
    account: SteamAccount | null;
} & Partial<ConnectedProps>) {
    const [showSettings, setShowSettings] = useState(false);

    if (account === null) {
        return (
            <>
                <Head title="Jogos" />
                <div className="flex flex-1 flex-col gap-6 p-4">
                    <PageHeader
                        title="Jogos"
                        description="Sua biblioteca da Steam: jogos, tempo de jogo, conquistas e estatísticas."
                    />
                    {configured ? (
                        <SteamConnectCard />
                    ) : (
                        <EmptyState
                            icon={Gamepad2}
                            title="Steam não configurada"
                            description="Gere uma chave em steamcommunity.com/dev/apikey e defina STEAM_API_KEY no .env do servidor para conectar as contas."
                        />
                    )}
                </div>
            </>
        );
    }

    const {
        summary,
        games: library = [],
        recent = [],
        top = [],
        months = [],
        days = [],
        history_since: historySince = null,
        recent_achievements: recentAchievements = [],
    } = props;

    return (
        <>
            <Head title="Jogos" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Jogos"
                    description="Sua biblioteca da Steam: jogos, tempo de jogo, conquistas e estatísticas."
                >
                    {configured && <SyncButton />}
                    <Button
                        variant="outline"
                        onClick={() => setShowSettings(!showSettings)}
                        aria-expanded={showSettings}
                    >
                        <Settings2 />
                        Steam
                    </Button>
                </PageHeader>

                {showSettings && <SteamAccountCard account={account} />}

                {account.sync_error && (
                    <Alert variant="destructive">
                        <AlertTriangle />
                        <AlertTitle>
                            A última sincronização não leu tudo
                        </AlertTitle>
                        <AlertDescription>
                            {account.sync_error}
                        </AlertDescription>
                    </Alert>
                )}

                <Profile account={account} />

                {summary && (
                    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <StatCard
                            title="Jogos"
                            value={summary.games.toLocaleString('pt-BR')}
                            hint={`${summary.played} jogados · ${summary.never_played} nunca jogados`}
                            icon={Library}
                        />
                        <StatCard
                            title="Tempo de jogo"
                            value={formatPlaytime(summary.playtime_minutes)}
                            hint={`${formatPlaytime(summary.playtime_2weeks_minutes)} nas últimas 2 semanas`}
                            icon={Clock}
                        />
                        <StatCard
                            title="Conquistas"
                            value={summary.achievements_unlocked.toLocaleString(
                                'pt-BR',
                            )}
                            hint={`de ${summary.achievements_total.toLocaleString('pt-BR')} · ${summary.perfect_games} ${summary.perfect_games === 1 ? 'jogo' : 'jogos'} 100%`}
                            icon={Trophy}
                        />
                        <StatCard
                            title="Conclusão média"
                            value={
                                summary.average_completion_bp !== null
                                    ? formatPercent(
                                          summary.average_completion_bp,
                                      )
                                    : '—'
                            }
                            hint={
                                account.pending_achievements > 0
                                    ? `Buscando conquistas de ${account.pending_achievements} ${account.pending_achievements === 1 ? 'jogo' : 'jogos'}…`
                                    : 'Nos jogos com alguma conquista'
                            }
                            icon={Award}
                        />
                    </div>
                )}

                {library.length === 0 ? (
                    <EmptyState
                        icon={Gamepad2}
                        title="Nenhum jogo na biblioteca"
                        description={
                            account.public_games
                                ? 'A Steam não mostrou nenhum jogo nesta conta.'
                                : 'Deixe os detalhes de jogo do perfil públicos na Steam e sincronize de novo.'
                        }
                    />
                ) : (
                    <>
                        {recent.length > 0 && <RecentlyPlayed games={recent} />}

                        <History
                            months={months}
                            days={days}
                            since={historySince}
                        />

                        {summary && top.length > 0 && (
                            <TopAndPlatforms top={top} summary={summary} />
                        )}

                        {recentAchievements.length > 0 && (
                            <RecentAchievements
                                achievements={recentAchievements}
                            />
                        )}

                        <LibraryGrid games={library} />
                    </>
                )}

                <p className="border-t pt-4 text-xs text-muted-foreground">
                    Dados e imagens da Steam. O Gamidas não é afiliado à Valve.
                </p>
            </div>
        </>
    );
}

GamesIndex.layout = {
    breadcrumbs: [{ title: 'Jogos', href: games.index() }],
};
