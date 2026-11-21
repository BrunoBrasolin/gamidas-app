import type { ComponentProps } from 'react';
import ExpensesIndex from '@/pages/expenses/index';
import personal from '@/routes/personal';

/**
 * The member's personal bills (due date, payment status, recurring ones):
 * the expenses listing in the personal scope, inside Finanças pessoais.
 */
export default function PersonalBills(
    props: ComponentProps<typeof ExpensesIndex>,
) {
    return <ExpensesIndex {...props} />;
}

PersonalBills.layout = {
    breadcrumbs: [
        { title: 'Finanças pessoais', href: personal.index() },
        { title: 'Contas', href: personal.bills() },
    ],
};
