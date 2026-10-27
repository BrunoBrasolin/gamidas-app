import { Head } from '@inertiajs/react';
import {
    CalendarDays,
    Clock,
    ExternalLink,
    ImageOff,
    Layers,
    Play,
    Star,
    User,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { formatDate, formatDateTime } from '@/lib/format';
import { formatClock, formatDuration } from '@/lib/time';
import {
    episodeCode,
    progressPercent,
    WATCH_TYPE_ICONS,
    watchHeading,
} from '@/lib/watched';
import watched from '@/routes/watched';
import type {
    TmdbDetails,
    TmdbEpisode,
    TmdbPerson,
    WatchedItem,
    WatchMedia,
    WatchProgress,
} from '@/types';

function Poster({ url, title }: { url: string | null; title: string }) {
    return url ? (
        <img
            src={url}
            alt={`Pôster de ${title}`}
            className="aspect-[2/3] w-32 shrink-0 rounded-lg border object-cover shadow-sm sm:w-44"
        />
    ) : (
        <div className="flex aspect-[2/3] w-32 shrink-0 items-center justify-center rounded-lg border bg-muted text-muted-foreground sm:w-44">
            <ImageOff className="size-6" aria-hidden />
        </div>
    );
}

/** Who watched it and when, or where they stopped. */
function Status({
    item,
    progress,
}: {
    item: WatchedItem | null;
    progress: WatchProgress | null;
}) {
    if (item) {
        return (
            <p className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
                <span>Assistido em {formatDateTime(item.watched_at)}</span>
                {item.viewer && (
                    <span className="flex items-center gap-1.5">
                        <User className="size-3.5" aria-hidden />
                        {item.viewer}
                    </span>
                )}
            </p>
        );
    }

    if (!progress) {
        return null;
    }

    const percent = progressPercent(
        progress.position_seconds,
        progress.runtime_seconds,
    );

    return (
        <div className="max-w-sm space-y-1.5">
            {percent !== null && (
                <div
                    className="h-1.5 overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    aria-valuenow={percent}
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-label="Quanto já foi assistido"
                >
                    <div
                        className="h-full rounded-full bg-primary"
                        style={{ width: `${percent}%` }}
                    />
                </div>
            )}
            <p className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
                {progress.playing ? (
                    <Badge className="gap-1">
                        <Play className="size-3" aria-hidden />
                        Assistindo agora
                    </Badge>
                ) : (
                    <span>
                        Parou em {formatClock(progress.position_seconds)}
                        {progress.runtime_seconds !== null &&
                            ` de ${formatClock(progress.runtime_seconds)}`}{' '}
                        · {formatDateTime(progress.last_played_at)}
                    </span>
                )}
                {progress.viewer && (
                    <span className="flex items-center gap-1.5">
                        <User className="size-3.5" aria-hidden />
                        {progress.viewer}
                    </span>
                )}
            </p>
        </div>
    );
}

function Facts({
    details,
    media,
}: {
    details: TmdbDetails | null;
    media: WatchMedia;
}) {
    const minutes =
        details?.runtime_minutes ??
        (media.runtime_seconds !== null
            ? Math.round(media.runtime_seconds / 60)
            : null);
    const year = details?.date?.slice(0, 4) ?? media.year;

    return (
        <p className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
            {year && (
                <span className="flex items-center gap-1.5">
                    <CalendarDays className="size-3.5" aria-hidden />
                    {year}
                </span>
            )}
            {minutes !== null && minutes > 0 && (
                <span className="flex items-center gap-1.5">
                    <Clock className="size-3.5" aria-hidden />
                    {formatDuration(minutes * 60)}
                </span>
            )}
            {details?.seasons && (
                <span className="flex items-center gap-1.5">
                    <Layers className="size-3.5" aria-hidden />
                    {details.seasons}{' '}
                    {details.seasons === 1 ? 'temporada' : 'temporadas'}
                </span>
            )}
            {details?.rating !== null && details?.rating !== undefined && (
                <span className="flex items-center gap-1.5">
                    <Star className="size-3.5" aria-hidden />
                    {details.rating.toLocaleString('pt-BR')}/10
                </span>
            )}
        </p>
    );
}

function EpisodeCard({
    episode,
    media,
}: {
    episode: TmdbEpisode | null;
    media: WatchMedia;
}) {
    const code = episodeCode({
        ...media,
        season_number: episode?.season_number ?? media.season_number,
        episode_number: episode?.episode_number ?? media.episode_number,
    });

    return (
        <Card className="py-4">
            <CardContent className="flex flex-col gap-4 px-4 sm:flex-row">
                {episode?.still_url && (
                    <img
                        src={episode.still_url}
                        alt=""
                        className="aspect-video w-full rounded-md object-cover sm:w-56"
                    />
                )}
                <div className="min-w-0 space-y-1.5">
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Episódio {code}
                    </p>
                    <h2 className="font-semibold">
                        {episode?.name ?? media.title}
                    </h2>
                    {(episode?.date || episode?.runtime_minutes) && (
                        <p className="text-sm text-muted-foreground">
                            {[
                                episode.date && formatDate(episode.date),
                                episode.runtime_minutes &&
                                    formatDuration(
                                        episode.runtime_minutes * 60,
                                    ),
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </p>
                    )}
                    {episode?.overview && (
                        <p className="text-sm leading-relaxed">
                            {episode.overview}
                        </p>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

function Cast({ people }: { people: TmdbPerson[] }) {
    return (
        <section className="space-y-3">
            <h2 className="text-sm font-medium text-muted-foreground">
                Elenco
            </h2>
            <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                {people.map((person) => (
                    <li
                        key={`${person.name}-${person.character}`}
                        className="flex items-center gap-3"
                    >
                        {person.photo_url ? (
                            <img
                                src={person.photo_url}
                                alt=""
                                loading="lazy"
                                className="size-12 shrink-0 rounded-full border object-cover"
                            />
                        ) : (
                            <div className="flex size-12 shrink-0 items-center justify-center rounded-full border bg-muted text-muted-foreground">
                                <User className="size-5" aria-hidden />
                            </div>
                        )}
                        <div className="min-w-0">
                            <p className="truncate text-sm font-medium">
                                {person.name}
                            </p>
                            {person.character && (
                                <p className="truncate text-xs text-muted-foreground">
                                    {person.character}
                                </p>
                            )}
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    );
}

export default function WatchedShow({
    item,
    progress,
    details,
    tmdb_configured: tmdbConfigured,
}: {
    item: WatchedItem | null;
    progress: WatchProgress | null;
    details: TmdbDetails | null;
    tmdb_configured: boolean;
}) {
    const media = (item ?? progress) as WatchMedia;
    const Icon = WATCH_TYPE_ICONS[media.type];
    const isEpisode = media.type === 'episode';
    const title =
        details?.title ??
        (isEpisode ? (media.series_name ?? media.title) : media.title);

    return (
        <>
            <Head title={watchHeading(media)} />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <div className="relative overflow-hidden rounded-xl border">
                    {details?.backdrop_url && (
                        <>
                            <img
                                src={details.backdrop_url}
                                alt=""
                                className="absolute inset-0 size-full object-cover opacity-25"
                            />
                            <div className="absolute inset-0 bg-gradient-to-t from-background via-background/80 to-background/30" />
                        </>
                    )}
                    <div className="relative flex flex-col gap-5 p-4 sm:flex-row sm:p-6">
                        <Poster
                            url={details?.poster_url ?? null}
                            title={title}
                        />
                        <div className="min-w-0 space-y-3">
                            <div className="space-y-1">
                                <p className="flex items-center gap-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    <Icon className="size-3.5" aria-hidden />
                                    {isEpisode
                                        ? 'Série'
                                        : media.type === 'movie'
                                          ? 'Filme'
                                          : 'Vídeo'}
                                </p>
                                <h1 className="text-2xl font-semibold tracking-tight">
                                    {title}
                                </h1>
                                {details?.original_title &&
                                    details.original_title !== title && (
                                        <p className="text-sm text-muted-foreground">
                                            {details.original_title}
                                        </p>
                                    )}
                            </div>

                            <Facts details={details} media={media} />

                            {details && details.genres.length > 0 && (
                                <div className="flex flex-wrap gap-1.5">
                                    {details.genres.map((genre) => (
                                        <Badge key={genre} variant="secondary">
                                            {genre}
                                        </Badge>
                                    ))}
                                </div>
                            )}

                            {details?.tagline && (
                                <p className="text-sm text-muted-foreground italic">
                                    {details.tagline}
                                </p>
                            )}
                            {details?.overview && (
                                <p className="max-w-3xl text-sm leading-relaxed">
                                    {details.overview}
                                </p>
                            )}
                            {details && details.creators.length > 0 && (
                                <p className="text-sm">
                                    <span className="text-muted-foreground">
                                        {details.kind === 'movie'
                                            ? 'Direção: '
                                            : 'Criação: '}
                                    </span>
                                    {details.creators.join(', ')}
                                </p>
                            )}

                            <Status item={item} progress={progress} />
                        </div>
                    </div>
                </div>

                {isEpisode && (
                    <EpisodeCard
                        episode={details?.episode ?? null}
                        media={media}
                    />
                )}

                {details && details.cast.length > 0 && (
                    <Cast people={details.cast} />
                )}

                {details ? (
                    <div className="flex flex-wrap items-center justify-between gap-2 border-t pt-4 text-xs text-muted-foreground">
                        <p>
                            Dados e imagens do TMDB. Este produto usa a API do
                            TMDB, mas não é endossado ou certificado pelo TMDB.
                        </p>
                        <Button variant="outline" size="sm" asChild>
                            <a
                                href={details.url}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <ExternalLink />
                                Ver no TMDB
                            </a>
                        </Button>
                    </div>
                ) : (
                    media.type !== 'video' && (
                        <p className="text-sm text-muted-foreground">
                            {tmdbConfigured
                                ? 'Não foi possível encontrar este item no TMDB agora.'
                                : 'Pôster, sinopse e elenco aparecem quando o TMDB estiver configurado no servidor (TMDB_TOKEN).'}
                        </p>
                    )
                )}
            </div>
        </>
    );
}

WatchedShow.layout = {
    breadcrumbs: [
        { title: 'Assistidos', href: watched.index() },
        { title: 'Detalhes', href: watched.index() },
    ],
};
