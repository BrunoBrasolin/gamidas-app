import { CheckCircle2, Circle } from 'lucide-react';
import { formatDate } from '@/lib/format';
import { clampPercent, formatMinutes } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { Task } from '@/types';

export function ProgressBar({
    percent,
    className,
    label,
}: {
    percent: number | null;
    className?: string;
    label: string;
}) {
    const value = clampPercent(percent);

    return (
        <div
            role="progressbar"
            aria-label={label}
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={value}
            className={cn(
                'h-2 overflow-hidden rounded-full bg-muted',
                className,
            )}
        >
            <div
                className="h-full rounded-full bg-primary transition-[width]"
                style={{ width: `${value}%` }}
            />
        </div>
    );
}

/**
 * Goal progress of a task. Time goals use manually recorded time only;
 * outcome goals are reached by completing the task.
 */
export function TaskProgress({ task }: { task: Task }) {
    const { progress } = task;
    const hasTime =
        task.goal_type === 'time' || task.goal_type === 'time_and_outcome';
    const hasOutcome =
        task.goal_type === 'outcome' || task.goal_type === 'time_and_outcome';

    return (
        <div className="space-y-1.5 text-sm">
            {hasTime && task.target_minutes !== null && (
                <>
                    <ProgressBar
                        percent={progress.percent}
                        label={`Progresso de ${task.title}`}
                    />
                    <div className="flex flex-wrap justify-between gap-x-3 text-xs text-muted-foreground">
                        <span>
                            {formatMinutes(progress.recorded_minutes)} de{' '}
                            {formatMinutes(task.target_minutes)} ·{' '}
                            {progress.percent ?? 0}%
                        </span>
                        <span>
                            {progress.remaining_minutes === 0
                                ? 'Meta de tempo atingida'
                                : `Faltam ${formatMinutes(progress.remaining_minutes ?? 0)}`}
                        </span>
                    </div>
                </>
            )}
            {!hasTime && (
                <div className="text-xs text-muted-foreground">
                    {formatMinutes(progress.recorded_minutes)} registrados
                </div>
            )}
            {hasOutcome && (
                <div className="flex items-center gap-1.5 text-xs">
                    {progress.outcome_done ? (
                        <CheckCircle2
                            className="size-3.5 text-emerald-600"
                            aria-hidden
                        />
                    ) : (
                        <Circle
                            className="size-3.5 text-muted-foreground"
                            aria-hidden
                        />
                    )}
                    <span>
                        {task.outcome ?? 'Resultado'}
                        {progress.outcome_done ? ' — alcançado' : ' — pendente'}
                    </span>
                </div>
            )}
            {task.deadline && (
                <div
                    className={cn(
                        'text-xs',
                        progress.overdue
                            ? 'text-destructive'
                            : 'text-muted-foreground',
                    )}
                >
                    Prazo {formatDate(task.deadline)}
                    {task.status !== 'completed' &&
                        progress.days_left !== null &&
                        (progress.overdue
                            ? ' · atrasada'
                            : progress.days_left === 0
                              ? ' · hoje'
                              : ` · ${progress.days_left} dia${progress.days_left === 1 ? '' : 's'}`)}
                </div>
            )}
        </div>
    );
}
