import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import time from '@/routes/time';

/**
 * Switch between the day detail and the week / month time sheet around a date.
 */
export function ViewSwitch({
    date,
    current = 'day',
}: {
    date: string;
    current?: 'day' | 'week' | 'month';
}) {
    const links = [
        ['day', 'Dia', time.days.show(date)],
        ['week', 'Semana', time.sheet({ query: { view: 'week', date } })],
        ['month', 'Mês', time.sheet({ query: { view: 'month', date } })],
    ] as const;

    return (
        <nav
            className="inline-flex rounded-lg bg-muted p-1 text-sm"
            aria-label="Visão"
        >
            {links.map(([value, label, href]) => (
                <Link
                    key={value}
                    href={href}
                    aria-current={current === value ? 'page' : undefined}
                    className={cn(
                        'rounded-md px-3 py-1.5 font-medium',
                        current === value
                            ? 'bg-background shadow-sm'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    {label}
                </Link>
            ))}
        </nav>
    );
}
