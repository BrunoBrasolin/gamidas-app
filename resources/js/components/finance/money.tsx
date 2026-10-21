import { formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';

export function Money({
    cents,
    className,
    signed = false,
}: {
    cents: number;
    className?: string;
    signed?: boolean;
}) {
    return (
        <span
            className={cn(
                'whitespace-nowrap tabular-nums',
                signed && cents < 0 && 'text-red-600 dark:text-red-400',
                signed && cents > 0 && 'text-emerald-700 dark:text-emerald-400',
                className,
            )}
        >
            {formatMoney(cents)}
        </span>
    );
}
