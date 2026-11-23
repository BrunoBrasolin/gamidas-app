import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import expenses from '@/routes/expenses';

/**
 * Switches between the household's monthly expenses and the recurring ones
 * that generate them.
 */
export function ExpenseTabs({
    current,
    month,
}: {
    current: 'month' | 'recurring';
    month?: string;
}) {
    const tabs = [
        {
            key: 'month',
            label: 'Mês a mês',
            href: expenses.index({ query: { month } }),
        },
        { key: 'recurring', label: 'Recorrentes', href: expenses.recurring() },
    ] as const;

    return (
        <nav
            aria-label="Visualização"
            className="inline-flex w-fit rounded-lg bg-muted p-1 text-sm"
        >
            {tabs.map((tab) => (
                <Link
                    key={tab.key}
                    href={tab.href}
                    preserveScroll
                    aria-current={current === tab.key ? 'page' : undefined}
                    className={cn(
                        'rounded-md px-4 py-1.5 font-medium transition-colors',
                        current === tab.key
                            ? 'bg-background shadow-sm'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    {tab.label}
                </Link>
            ))}
        </nav>
    );
}
