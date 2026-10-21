import { AlertTriangle, CheckCircle2, Clock } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { OccurrenceStatus } from '@/types';

export function StatusBadge({
    status,
    overdue = false,
    className,
}: {
    status: OccurrenceStatus;
    overdue?: boolean;
    className?: string;
}) {
    const variant =
        status === 'paid' ? 'paid' : overdue ? 'overdue' : 'pending';

    const styles = {
        paid: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300',
        pending:
            'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300',
        overdue:
            'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-300',
    }[variant];

    const Icon = { paid: CheckCircle2, pending: Clock, overdue: AlertTriangle }[
        variant
    ];

    const label = { paid: 'Pago', pending: 'Pendente', overdue: 'Vencido' }[
        variant
    ];

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs font-medium whitespace-nowrap',
                styles,
                className,
            )}
        >
            <Icon className="size-3" aria-hidden />
            {label}
        </span>
    );
}
