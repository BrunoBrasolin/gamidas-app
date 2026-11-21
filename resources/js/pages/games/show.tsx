import { Head } from '@inertiajs/react';
import {
    CalendarDays,
    Clock,
    ExternalLink,
    Eye,
    Gamepad2,
    Globe,
    Lock,
    Monitor,
    Star,
    Tag,
    ThumbsUp,
    Trophy,
} from 'lucide-react';
import { useState } from 'react';
import { BarChart, MeterRow } from '@/components/games/bar-chart';
import { GameImage } from '@/components/games/game-image';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    capitalize,
    formatDateTime,
    formatMonth,
    formatShortMonth,
} from '@/lib/format';
import {
    completion,
    formatPlaytime,
    formatShare,
    formatStat,
    statLabel,
} from '@/lib/games';
import { cn } from '@/lib/utils';
import games from '@/routes/games';
import type {
    SteamAchievement,
    SteamGameDetails,
    SteamGameStat,
    SteamMonth,
    SteamStoreDetails,
} from '@/types';

type AchievementFilter = 'all' | 'unlocked' | 'locked';

/** Below this share of players, an achievement is rare. */
const RARE_BP = 1_000;

const PLATFORM_NAMES: Record<string, string> = {
    windows: 'Windows',
    mac: 'macOS',
    linux: 'Linux',
};

function Fact({
    icon: Icon,
    children,
}: {
    icon: typeof Clock;
    children: React.ReactNode;
}) {
    return (
        <span className="flex items-center gap-1.5">
            <Icon className="size-3.5" aria-hidden />
            {children}
        </span>
    );
}

function StoreFacts({ store }: { store: SteamStoreDetails }) {
    return (
        <div className="space-y-2 text-sm">
            <p className="flex flex-wrap items-center gap-x-4 gap-y-1 text-muted-foreground">
                {store.release_date && (
                    <Fact icon={CalendarDays}>
                        {store.coming_soon
                            ? `Lançamento: ${store.release_date}`
                            : store.release_date}
                    </Fact>
                )}
                {store.metacritic && (
                    <Fact icon={Star}>
                        {store.metacritic.url ? (
                            <a
                                href={store.metacritic.url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="hover:underline"
                            >
                                Metacritic {store.metacritic.score}
                            </a>
                        ) : (
                            `Metacritic ${store.metacritic.score}`
                        )}
                    </Fact>
                )}
                {store.recommendations !== null && (
                    <Fact icon={ThumbsUp}>
                        {store.recommendations.toLocaleString('pt-BR')} análises
                    </Fact>
                )}
                {(store.price || store.is_free) && (
                    <Fact icon={Tag}>
                        {store.is_free ? 'Gratuito' : store.price}
                        {store.discount_percent > 0 &&
                            ` (−${store.discount_percent}%)`}
                    </Fact>
                )}
                {store.platforms.length > 0 && (
                    <Fact icon={Monitor}>
                        {store.platforms
                            .map((platform) => PLATFORM_NAMES[platform])
                            .join(', ')}
                    </Fact>
                )}
                {store.controller_support && (
                    <Fact icon={Gamepad2}>
                        {store.controller_support === 'full'
                            ? 'Suporte total a controle'
                            : 'Suporte parcial a controle'}
                    </Fact>
                )}
            </p>
            {store.developers.length > 0 && (
                <p>
                    <span className="text-muted-foreground">
                        Desenvolvimento:{' '}
                    </span>
                    {store.developers.join(', ')}
                </p>
            )}
            {store.publishers.length > 0 && (
                <p>
                    <span className="text-muted-foreground">Publicação: </span>
                    {store.publishers.join(', ')}
                </p>
            )}
        </div>
    );
}

function Playtime({
    game,
    months,
}: {
    game: SteamGameDetails;
    months: SteamMonth[];
}) {
    const platforms = [
        { key: 'windows', label: 'Windows' },
        { key: 'linux', label: 'Linux' },
        { key: 'deck', label: 'Steam Deck' },
        { key: 'mac', label: 'macOS' },
    ] as const;
    const withPlatform = platforms.filter(
        (platform) => game.platforms[platform.key] > 0,
    );

    return (
        <div className="grid gap-4 xl:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>Seu tempo de jogo</CardTitle>
                    <CardDescription>
                        {game.playtime_minutes > 0
                            ? `${formatPlaytime(game.playtime_minutes)} no total · ${formatPlaytime(game.playtime_2weeks_minutes)} nas últimas 2 semanas`
                            : 'Você ainda não jogou.'}
                        {game.last_played_at &&
                            ` · jogado por último em ${formatDateTime(game.last_played_at)}`}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {withPlatform.length > 0 ? (
                        <ul className="space-y-3">
                            {withPlatform.map((platform) => (
                                <MeterRow
                                    key={platform.key}
                                    label={platform.label}
                                    value={game.platforms[platform.key]}
                                    max={game.playtime_minutes}
                                    display={formatPlaytime(
                                        game.platforms[platform.key],
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
            <Card>
                <CardHeader>
                    <CardTitle>Tempo por mês</CardTitle>
                    <CardDescription>
                        Registrado pelo Gamidas a cada sincronização.
                    </CardDescription>
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
        </div>
    );
}

/** Shown before "Mostrar todas". */
const STATS_PREVIEW = 12;

function GameStats({ stats }: { stats: SteamGameStat[] }) {
    const [all, setAll] = useState(false);
    const shown = all ? stats : stats.slice(0, STATS_PREVIEW);

    return (
        <Card>
            <CardHeader>
                <CardTitle>Estatísticas do jogo</CardTitle>
                <CardDescription>
                    Contadores que o próprio jogo guarda na Steam; os nomes são
                    os do jogo.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
                <dl className="grid gap-x-6 gap-y-2 sm:grid-cols-2 xl:grid-cols-3">
                    {shown.map((stat) => (
                        <div
                            key={stat.name}
                            className="flex items-baseline justify-between gap-3 border-b py-1 text-sm"
                        >
                            <dt
                                className="truncate text-muted-foreground"
                                title={stat.name}
                            >
                                {statLabel(stat)}
                            </dt>
                            <dd className="shrink-0 font-medium tabular-nums">
                                {formatStat(stat.value)}
                            </dd>
                        </div>
                    ))}
                </dl>
                {stats.length > STATS_PREVIEW && (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setAll(!all)}
                    >
                        {all
                            ? 'Mostrar menos'
                            : `Mostrar todas (${stats.length})`}
                    </Button>
                )}
            </CardContent>
        </Card>
    );
}

function AchievementRow({ achievement }: { achievement: SteamAchievement }) {
    const [revealed, setRevealed] = useState(false);
    const secret = achievement.hidden && !achievement.achieved && !revealed;
    const rare =
        achievement.global_percent_bp !== null &&
        achievement.global_percent_bp < RARE_BP;

    return (
        <li
            className={cn(
                'flex items-center gap-3 rounded-lg border p-3',
                !achievement.achieved && 'bg-muted/40',
            )}
        >
            {achievement.icon_url ? (
                <img
                    src={achievement.icon_url}
                    alt=""
                    loading="lazy"
                    className={cn(
                        'size-12 shrink-0 rounded-md border',
                        !achievement.achieved && 'opacity-70',
                    )}
                />
            ) : (
                <div className="flex size-12 shrink-0 items-center justify-center rounded-md border bg-muted">
                    <Trophy
                        className="size-5 text-muted-foreground"
                        aria-hidden
                    />
                </div>
            )}
            <div className="min-w-0 flex-1 space-y-0.5">
                <p className="flex flex-wrap items-center gap-2 text-sm font-medium">
                    {secret ? 'Conquista oculta' : achievement.name}
                    {!achievement.achieved && (
                        <Lock
                            className="size-3.5 text-muted-foreground"
                            aria-label="Bloqueada"
                        />
                    )}
                    {rare && (
                        <Badge
                            variant="outline"
                            className="border-amber-500/50 text-amber-700 dark:text-amber-400"
                        >
                            Rara
                        </Badge>
                    )}
                </p>
                {secret ? (
                    <button
                        type="button"
                        onClick={() => setRevealed(true)}
                        className="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                    >
                        <Eye className="size-3.5" aria-hidden />
                        Mostrar (pode ter spoiler)
                    </button>
                ) : (
                    achievement.description && (
                        <p className="text-xs text-muted-foreground">
                            {achievement.description}
                        </p>
                    )
                )}
                <p className="text-xs text-muted-foreground">
                    {[
                        achievement.unlocked_at &&
                            `Desbloqueada em ${formatDateTime(achievement.unlocked_at)}`,
                        achievement.global_percent_bp !== null &&
                            `${formatShare(achievement.global_percent_bp)} dos jogadores`,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </p>
            </div>
        </li>
    );
}

function Achievements({
    game,
    achievements,
    error,
}: {
    game: SteamGameDetails;
    achievements: SteamAchievement[];
    error: string | null;
}) {
    const [filter, setFilter] = useState<AchievementFilter>('all');
    const percent = completion(game);
    const shown = achievements.filter((achievement) =>
        filter === 'all'
            ? true
            : filter === 'unlocked'
              ? achievement.achieved
              : !achievement.achieved,
    );

    if (achievements.length === 0) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>Conquistas</CardTitle>
                    <CardDescription>
                        {error ??
                            (game.achievements_synced_at
                                ? 'Este jogo não tem conquistas na Steam.'
                                : 'As conquistas deste jogo ainda não foram buscadas.')}
                    </CardDescription>
                </CardHeader>
            </Card>
        );
    }

    return (
        <section className="space-y-3">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div className="space-y-1.5">
                    <h2 className="flex items-center gap-2 font-semibold">
                        <Trophy className="size-4" aria-hidden />
                        Conquistas
                        <span className="text-sm font-normal text-muted-foreground">
                            {game.achievements_unlocked} de{' '}
                            {game.achievements_total}
                            {percent !== null && ` (${Math.floor(percent)}%)`}
                        </span>
                    </h2>
                    {percent !== null && (
                        <div
                            className="h-2 w-64 max-w-full overflow-hidden rounded-full bg-muted"
                            role="progressbar"
                            aria-label="Conquistas desbloqueadas"
                            aria-valuemin={0}
                            aria-valuemax={100}
                            aria-valuenow={Math.floor(percent)}
                        >
                            <div
                                className={cn(
                                    'h-full rounded-full',
                                    percent >= 100
                                        ? 'bg-amber-500'
                                        : 'bg-primary',
                                )}
                                style={{ width: `${percent}%` }}
                            />
                        </div>
                    )}
                </div>
                <ToggleGroup
                    type="single"
                    variant="outline"
                    value={filter}
                    onValueChange={(value) =>
                        value && setFilter(value as AchievementFilter)
                    }
                    aria-label="Filtrar conquistas"
                >
                    <ToggleGroupItem value="all">Todas</ToggleGroupItem>
                    <ToggleGroupItem value="unlocked">
                        Desbloqueadas
                    </ToggleGroupItem>
                    <ToggleGroupItem value="locked">Bloqueadas</ToggleGroupItem>
                </ToggleGroup>
            </div>
            {error && (
                <Alert>
                    <AlertDescription>
                        Mostrando o que foi buscado antes: {error}
                    </AlertDescription>
                </Alert>
            )}
            {shown.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Nenhuma conquista aqui.
                </p>
            ) : (
                <ul className="grid gap-2 lg:grid-cols-2">
                    {shown.map((achievement) => (
                        <AchievementRow
                            key={achievement.id}
                            achievement={achievement}
                        />
                    ))}
                </ul>
            )}
        </section>
    );
}

export default function GameShow({
    game,
    achievements,
    achievements_error: achievementsError,
    months,
    store,
}: {
    game: SteamGameDetails;
    achievements: SteamAchievement[];
    achievements_error: string | null;
    months: SteamMonth[];
    store: SteamStoreDetails | null;
}) {
    return (
        <>
            <Head title={game.name} />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <div className="relative overflow-hidden rounded-xl border">
                    {store?.background_url && (
                        <>
                            <img
                                src={store.background_url}
                                alt=""
                                className="absolute inset-0 size-full object-cover opacity-25"
                            />
                            <div className="absolute inset-0 bg-gradient-to-t from-background via-background/80 to-background/30" />
                        </>
                    )}
                    <div className="relative flex flex-col gap-5 p-4 sm:p-6 lg:flex-row">
                        <GameImage
                            urls={[
                                store?.header_url ?? null,
                                game.header_url,
                                game.icon_url,
                            ]}
                            name={game.name}
                            className="aspect-[460/215] w-full shrink-0 self-start rounded-lg border shadow-sm sm:w-96"
                        />
                        <div className="min-w-0 space-y-3">
                            <div className="space-y-1">
                                <p className="flex items-center gap-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    <Gamepad2
                                        className="size-3.5"
                                        aria-hidden
                                    />
                                    Steam
                                    {game.removed_at && (
                                        <Badge variant="outline">
                                            Fora da biblioteca
                                        </Badge>
                                    )}
                                </p>
                                <h1 className="text-2xl font-semibold tracking-tight">
                                    {game.name}
                                </h1>
                            </div>

                            <p className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
                                <Fact icon={Clock}>
                                    {game.playtime_minutes > 0
                                        ? formatPlaytime(game.playtime_minutes)
                                        : 'Nunca jogado'}
                                </Fact>
                                {game.achievements_total !== null &&
                                    game.achievements_total > 0 && (
                                        <Fact icon={Trophy}>
                                            {game.achievements_unlocked}/
                                            {game.achievements_total} conquistas
                                        </Fact>
                                    )}
                            </p>

                            {store && store.genres.length > 0 && (
                                <div className="flex flex-wrap gap-1.5">
                                    {store.genres.map((genre) => (
                                        <Badge key={genre} variant="secondary">
                                            {genre}
                                        </Badge>
                                    ))}
                                </div>
                            )}

                            {store?.description && (
                                <p className="max-w-3xl text-sm leading-relaxed">
                                    {store.description}
                                </p>
                            )}

                            {store && <StoreFacts store={store} />}

                            <div className="flex flex-wrap gap-2">
                                <Button variant="outline" size="sm" asChild>
                                    <a
                                        href={game.store_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        <ExternalLink />
                                        Ver na loja
                                    </a>
                                </Button>
                                <Button variant="outline" size="sm" asChild>
                                    <a href={`steam://run/${game.app_id}`}>
                                        <Gamepad2 />
                                        Jogar
                                    </a>
                                </Button>
                                {store?.website && (
                                    <Button variant="ghost" size="sm" asChild>
                                        <a
                                            href={store.website}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            <Globe />
                                            Site oficial
                                        </a>
                                    </Button>
                                )}
                            </div>
                        </div>
                    </div>
                </div>

                <Playtime game={game} months={months} />

                <Achievements
                    game={game}
                    achievements={achievements}
                    error={achievementsError}
                />

                {game.stats.length > 0 && <GameStats stats={game.stats} />}

                {store && store.categories.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="text-sm font-medium text-muted-foreground">
                            Recursos
                        </h2>
                        <div className="flex flex-wrap gap-1.5">
                            {store.categories.map((category) => (
                                <Badge key={category} variant="outline">
                                    {category}
                                </Badge>
                            ))}
                        </div>
                    </section>
                )}

                {store && store.screenshots.length > 0 && (
                    <section className="space-y-3">
                        <h2 className="text-sm font-medium text-muted-foreground">
                            Capturas de tela
                        </h2>
                        <ul className="grid grid-cols-2 gap-2 md:grid-cols-4">
                            {store.screenshots.map((shot) => (
                                <li key={shot.full_url}>
                                    <a
                                        href={shot.full_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        <img
                                            src={shot.thumb_url}
                                            alt=""
                                            loading="lazy"
                                            className="aspect-video w-full rounded-md border object-cover transition-opacity hover:opacity-80"
                                        />
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <p className="border-t pt-4 text-xs text-muted-foreground">
                    Dados e imagens da Steam. O Gamidas não é afiliado à Valve.
                </p>
            </div>
        </>
    );
}

GameShow.layout = (props: { game: SteamGameDetails }) => ({
    breadcrumbs: [
        { title: 'Jogos', href: games.index() },
        { title: props.game.name, href: games.show(props.game.id) },
    ],
});
