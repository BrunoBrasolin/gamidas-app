import { Link } from '@inertiajs/react';
import { expensesPage, recurringPage } from '@/lib/expense-pages';
import { cn } from '@/lib/utils';
import type { Scope } from '@/types';

/**
 * Switches between the monthly expenses of a scope and the recurring ones
 * that generate them.
 */
export function ExpenseTabs({
    scope,
    current,
    month,
}: {
    scope: Scope;
    current: 'month' | 'recurring';
    month?: string;
}) {
    const tabs = [
        {
            key: 'month',
            label: 'Mês a mês',
            href: expensesPage(scope, { month }),
        },
        { key: 'recurring', label: 'Recorrentes', href: recurringPage(scope) },
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
