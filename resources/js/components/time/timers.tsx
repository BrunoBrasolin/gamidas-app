import { router } from '@inertiajs/react';
import { Pause, Play, RotateCcw, SquareArrowDown } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { formatClock } from '@/lib/time';
import time from '@/routes/time';
import type { CategoryTimer, TimerList } from '@/types';

/**
 * One stopwatch per category. The server owns the clock; the browser counts
 * from the server time it received, so a reload or another device shows the
 * same value.
 */
export function Timers({ timers }: { timers: TimerList }) {
    // How far the browser clock is from the server's, refreshed with each response.
    const [offset, setOffset] = useState(() => timers.now - Date.now());
    const [now, setNow] = useState(() => Date.now() + offset);
    const anyRunning = timers.items.some((item) => item.started_at !== null);

    useEffect(() => {
        const fresh = timers.now - Date.now();

        setOffset(fresh);
        setNow(Date.now() + fresh);
    }, [timers.now]);

    useEffect(() => {
        if (!anyRunning) {
            return;
        }

        const id = window.setInterval(() => setNow(Date.now() + offset), 250);

        return () => window.clearInterval(id);
    }, [anyRunning, offset]);

    if (timers.items.length === 0) {
        return null;
    }

    return (
        <section aria-label="Cronômetros" className="space-y-2">
            <h2 className="text-sm font-medium text-muted-foreground">
                Cronômetros
            </h2>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                {timers.items.map((item) => (
                    <TimerCard key={item.category_id} item={item} now={now} />
                ))}
            </div>
        </section>
    );
}

function TimerCard({ item, now }: { item: CategoryTimer; now: number }) {
    const running = item.started_at !== null;
    const elapsed =
        item.accumulated_seconds +
        (item.started_at === null
            ? 0
            : Math.max(0, Math.floor((now - item.started_at) / 1000)));
    const category = { category: item.category_id };

    const post = (action: 'start' | 'pause' | 'commit') =>
        router.post(
            time.timer[action].url(category),
            {},
            { preserveScroll: true },
        );

    return (
        <div className="flex items-center justify-between gap-3 rounded-xl border p-3">
            <div className="min-w-0">
                <p className="truncate text-sm font-medium">{item.name}</p>
                <p
                    className="text-2xl font-semibold tabular-nums"
                    aria-live="off"
                    data-running={running}
                >
                    {formatClock(elapsed)}
                </p>
            </div>
            <div className="flex shrink-0 gap-1">
                {running ? (
                    <Button
                        size="icon"
                        variant="outline"
                        aria-label={`Pausar ${item.name}`}
                        onClick={() => post('pause')}
                    >
                        <Pause />
                    </Button>
                ) : (
                    <Button
                        size="icon"
                        aria-label={`Iniciar ${item.name}`}
                        disabled={item.archived}
                        onClick={() => post('start')}
                    >
                        <Play />
                    </Button>
                )}
                <Button
                    size="icon"
                    variant="outline"
                    aria-label={`Lançar ${item.name} na planilha de hoje`}
                    title="Lançar na planilha de hoje"
                    disabled={elapsed === 0}
                    onClick={() => post('commit')}
                >
                    <SquareArrowDown />
                </Button>
                <Button
                    size="icon"
                    variant="outline"
                    aria-label={`Zerar ${item.name}`}
                    title="Zerar"
                    disabled={elapsed === 0 && !running}
                    onClick={() =>
                        router.delete(time.timer.reset.url(category), {
                            preserveScroll: true,
                        })
                    }
                >
                    <RotateCcw />
                </Button>
            </div>
        </div>
    );
}
