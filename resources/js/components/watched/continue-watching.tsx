import { Link } from '@inertiajs/react';
import { Play, User, X } from 'lucide-react';
import { ConfirmDialog } from '@/components/finance/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { formatDateTime } from '@/lib/format';
import { formatClock } from '@/lib/time';
import {
    episodeCode,
    progressPercent,
    WATCH_TYPE_ICONS,
    watchHeading,
} from '@/lib/watched';
import watched from '@/routes/watched';
import type { WatchedItem, WatchProgress } from '@/types';

function ProgressCard({ progress }: { progress: WatchProgress }) {
    const Icon = WATCH_TYPE_ICONS[progress.type];
    const percent = progressPercent(
        progress.position_seconds,
        progress.runtime_seconds,
    );

    return (
        <Card className="h-full gap-0 py-3">
            <CardContent className="flex h-full flex-col gap-2 px-4">
                <div className="flex items-start gap-2">
                    <Icon
                        className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        aria-hidden
                    />
                    <Link
                        href={watched.progress.show(progress.id)}
                        className="min-w-0 flex-1 font-medium hover:underline"
                    >
                        {watchHeading(progress)}
                    </Link>
                    <ConfirmDialog
                        trigger={
                            <Button
                                variant="ghost"
                                size="icon"
                                className="-mt-1 -mr-2 size-7"
                                aria-label={`Tirar ${progress.title} de Continuar assistindo`}
                            >
                                <X />
                            </Button>
                        }
                        title="Tirar de Continuar assistindo?"
                        description={`“${watchHeading(progress)}” sai da lista. Se alguém voltar a assistir, ele reaparece.`}
                        confirmLabel="Tirar"
                        url={watched.progress.destroy.url(progress.id)}
                    />
                </div>
                <div className="mt-auto space-y-1.5">
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
                    <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                        {progress.playing ? (
                            <Badge className="gap-1">
                                <Play className="size-3" aria-hidden />
                                Assistindo agora
                            </Badge>
                        ) : (
                            <span>
                                Parou em{' '}
                                {formatClock(progress.position_seconds)}
                                {progress.runtime_seconds !== null &&
                                    ` de ${formatClock(progress.runtime_seconds)}`}
                            </span>
                        )}
                        {progress.viewer && (
                            <span className="flex items-center gap-1">
                                <User className="size-3" aria-hidden />
                                {progress.viewer}
                            </span>
                        )}
                        {!progress.playing && (
                            <span>
                                {formatDateTime(progress.last_played_at)}
                            </span>
                        )}
                    </p>
                </div>
            </CardContent>
        </Card>
    );
}

/**
 * What the household started and has not finished, with where each person
 * stopped.
 */
export function ContinueWatching({ items }: { items: WatchProgress[] }) {
    return (
        <section className="space-y-3">
            <h2 className="text-sm font-medium text-muted-foreground">
                Continuar assistindo
            </h2>
            <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                {items.map((progress) => (
                    <li key={progress.id}>
                        <ProgressCard progress={progress} />
                    </li>
                ))}
            </ul>
        </section>
    );
}

/**
 * The last episode watched of each recent series.
 */
export function SeriesInProgress({ episodes }: { episodes: WatchedItem[] }) {
    return (
        <section className="space-y-3">
            <h2 className="text-sm font-medium text-muted-foreground">
                Séries em andamento
            </h2>
            <ul className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                {episodes.map((episode) => (
                    <li key={episode.id}>
                        <Link
                            href={watched.show(episode.id)}
                            className="flex h-full flex-col gap-0.5 rounded-xl border px-4 py-3 transition-colors hover:bg-accent/40"
                        >
                            <span className="font-medium">
                                {episode.series_name}
                            </span>
                            <span className="text-sm text-muted-foreground">
                                Parou em{' '}
                                {[episodeCode(episode), episode.title]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {formatDateTime(episode.watched_at)}
                                {episode.viewer && ` · ${episode.viewer}`}
                            </span>
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}
