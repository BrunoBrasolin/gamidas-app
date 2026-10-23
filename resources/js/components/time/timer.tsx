import { Link, router, usePage } from '@inertiajs/react';
import { Pause, Play, Square, Timer as TimerIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { formatElapsed } from '@/lib/time';
import { cn } from '@/lib/utils';
import time from '@/routes/time';
import type { TaskOption, TimerSession } from '@/types';

/**
 * Elapsed seconds of a session, ticking while it runs. The server's value is
 * authoritative; the browser only adds the time passed since it was received
 * (measured with a monotonic clock, so a wrong system clock doesn't matter).
 * Callers remount it (via key) whenever the server sends a new value.
 */
function useElapsed(session: TimerSession): number {
    const [receivedAt] = useState(() => performance.now());
    const [now, setNow] = useState(receivedAt);
    const running = session.status === 'running';

    useEffect(() => {
        if (!running) {
            return;
        }

        const id = window.setInterval(() => setNow(performance.now()), 1000);

        return () => window.clearInterval(id);
    }, [running]);

    return running
        ? session.elapsed_seconds + Math.floor((now - receivedAt) / 1000)
        : session.elapsed_seconds;
}

/**
 * Reload the timer whenever the tab becomes visible again, so a timer
 * changed in another tab or device shows its real state.
 */
export function useTimerSync(only: string[] = ['timer']): void {
    const key = only.join(',');

    useEffect(() => {
        const props = key.split(',');
        const refresh = () => {
            if (document.visibilityState === 'visible') {
                router.reload({ only: props });
            }
        };

        document.addEventListener('visibilitychange', refresh);

        return () => document.removeEventListener('visibilitychange', refresh);
    }, [key]);
}

type Existing = 'pause' | 'stop';

/**
 * Timer actions with server-side state. When another timer is running the
 * server refuses to start a second one, and the user decides what to do
 * with the running timer in a dialog.
 */
export function useTimerActions() {
    const [processing, setProcessing] = useState(false);
    const [conflict, setConflict] = useState<{
        message: string;
        retry: (existing: Existing) => void;
    } | null>(null);

    function post(
        url: string,
        data: Record<string, string | number>,
        retry?: (existing: Existing) => void,
    ) {
        router.post(url, data, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => setConflict(null),
            onError: (errors) => {
                if (errors.timer_conflict && retry) {
                    setConflict({ message: errors.timer_conflict, retry });
                }
            },
        });
    }

    function start(taskId: number) {
        const url = time.timer.start.url();
        post(url, { task_id: taskId }, (existing) =>
            post(url, { task_id: taskId, existing }),
        );
    }

    function resume(session: TimerSession) {
        const url = time.timer.resume.url(session.id);
        post(url, {}, (existing) => post(url, { existing }));
    }

    const dialog = (
        <Dialog
            open={conflict !== null}
            onOpenChange={(open) => !open && setConflict(null)}
        >
            <DialogContent>
                <DialogTitle>Já existe um cronômetro em andamento</DialogTitle>
                <DialogDescription>{conflict?.message}</DialogDescription>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">Cancelar</Button>
                    </DialogClose>
                    <Button
                        variant="outline"
                        disabled={processing}
                        onClick={() => conflict?.retry('stop')}
                    >
                        Parar o atual
                    </Button>
                    <Button
                        disabled={processing}
                        onClick={() => conflict?.retry('pause')}
                    >
                        {processing && <Spinner />}
                        Pausar o atual
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );

    return {
        processing,
        dialog,
        start,
        resume,
        pause: (session: TimerSession) =>
            post(time.timer.pause.url(session.id), {}),
        stop: (session: TimerSession) =>
            post(time.timer.stop.url(session.id), {}),
    };
}

export function TimerControls({
    session,
    actions,
    size = 'default',
}: {
    session: TimerSession;
    actions: ReturnType<typeof useTimerActions>;
    size?: 'default' | 'sm';
}) {
    const iconSize = size === 'sm' ? 'icon' : 'default';

    return (
        <div className="flex items-center gap-1.5">
            {session.status === 'running' ? (
                <Button
                    type="button"
                    variant="outline"
                    size={iconSize}
                    disabled={actions.processing}
                    onClick={() => actions.pause(session)}
                    aria-label={`Pausar ${session.task.title}`}
                >
                    <Pause />
                    {size === 'default' && 'Pausar'}
                </Button>
            ) : (
                <Button
                    type="button"
                    variant="outline"
                    size={iconSize}
                    disabled={actions.processing || session.task.archived}
                    onClick={() => actions.resume(session)}
                    aria-label={`Retomar ${session.task.title}`}
                >
                    <Play />
                    {size === 'default' && 'Retomar'}
                </Button>
            )}
            <Button
                type="button"
                variant={size === 'sm' ? 'outline' : 'default'}
                size={iconSize}
                disabled={actions.processing}
                onClick={() => actions.stop(session)}
                aria-label={`Parar ${session.task.title}`}
            >
                <Square />
                {size === 'default' && 'Parar'}
            </Button>
        </div>
    );
}

export function ElapsedClock({
    session,
    className,
}: {
    session: TimerSession;
    className?: string;
}) {
    return (
        <Ticker
            key={`${session.id}:${session.status}:${session.elapsed_seconds}`}
            session={session}
            className={className}
        />
    );
}

function Ticker({
    session,
    className,
}: {
    session: TimerSession;
    className?: string;
}) {
    const elapsed = useElapsed(session);

    return (
        <span
            className={cn('font-mono tabular-nums', className)}
            role="timer"
            aria-label={`Tempo medido: ${formatElapsed(elapsed)}`}
        >
            {formatElapsed(elapsed)}
        </span>
    );
}

/**
 * The current timer with its controls, or a task picker to start one.
 */
export function TimerPanel({
    tasks,
    footer,
}: {
    tasks: TaskOption[];
    footer?: ReactNode;
}) {
    const timer = usePage().props.timer;
    const actions = useTimerActions();
    const startable = tasks.filter((task) => task.status !== 'archived');
    const [taskId, setTaskId] = useState<string>('');
    const selected = taskId || String(startable[0]?.id ?? '');

    return (
        <div className="space-y-4">
            {timer ? (
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div className="space-y-1">
                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                            <span
                                className={cn(
                                    'size-2 rounded-full',
                                    timer.status === 'running'
                                        ? 'animate-pulse bg-emerald-500'
                                        : 'bg-amber-500',
                                )}
                                aria-hidden
                            />
                            {timer.status === 'running'
                                ? 'Em andamento'
                                : 'Pausado'}
                        </div>
                        <div className="font-medium">{timer.task.title}</div>
                        <ElapsedClock
                            session={timer}
                            className="text-4xl font-semibold"
                        />
                    </div>
                    <TimerControls session={timer} actions={actions} />
                </div>
            ) : (
                <p className="text-sm text-muted-foreground">
                    Nenhum cronômetro em andamento.
                </p>
            )}

            {startable.length > 0 ? (
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();

                        if (selected) {
                            actions.start(Number(selected));
                        }
                    }}
                >
                    <div className="grid min-w-48 flex-1 gap-1.5">
                        <Label htmlFor="timer-task">
                            {timer ? 'Trocar para outra tarefa' : 'Tarefa'}
                        </Label>
                        <NativeSelect
                            id="timer-task"
                            value={selected}
                            onChange={(event) => setTaskId(event.target.value)}
                        >
                            {startable.map((task) => (
                                <option key={task.id} value={task.id}>
                                    {task.title}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <Button
                        type="submit"
                        variant={timer ? 'outline' : 'default'}
                        disabled={actions.processing}
                    >
                        {actions.processing ? <Spinner /> : <Play />}
                        Iniciar
                    </Button>
                </form>
            ) : (
                <p className="text-sm text-muted-foreground">
                    Crie uma tarefa para usar o cronômetro.
                </p>
            )}
            {footer}
            {actions.dialog}
        </div>
    );
}

/**
 * Compact timer shown in the page header on every page.
 */
export function TimerIndicator() {
    const timer = usePage().props.timer;
    const actions = useTimerActions();

    useTimerSync();

    if (!timer) {
        return null;
    }

    return (
        <div className="ml-auto flex items-center gap-2 print:hidden">
            <Link
                href={time.timer.index()}
                className="flex min-w-0 items-center gap-2 rounded-md px-2 py-1 text-sm hover:bg-muted"
            >
                <TimerIcon
                    className={cn(
                        'size-4 shrink-0',
                        timer.status === 'running'
                            ? 'text-emerald-600'
                            : 'text-amber-600',
                    )}
                    aria-hidden
                />
                <span className="max-w-32 truncate max-sm:hidden">
                    {timer.task.title}
                </span>
                <ElapsedClock session={timer} className="text-sm" />
                {timer.status === 'paused' && (
                    <span className="text-xs text-muted-foreground">
                        pausado
                    </span>
                )}
            </Link>
            <TimerControls session={timer} actions={actions} size="sm" />
            {actions.dialog}
        </div>
    );
}
