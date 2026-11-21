import expenses from '@/routes/expenses';
import personal from '@/routes/personal';
import type { Scope } from '@/types';

type Query = Record<string, string | number | null | undefined>;

/**
 * The month-by-month listing of a scope: Despesas da casa, or the personal
 * bills in Finanças pessoais.
 */
export function expensesPage(scope: Scope, query: Query = {}) {
    return scope === 'personal'
        ? personal.bills({ query })
        : expenses.index({ query });
}

/** The recurring expenses of a scope, a tab next to its monthly listing. */
export function recurringPage(scope: Scope, query: Query = {}) {
    return scope === 'personal'
        ? personal.bills.recurring({ query })
        : expenses.recurring({ query });
}
