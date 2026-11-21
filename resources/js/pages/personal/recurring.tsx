import type { ComponentProps } from 'react';
import RecurringExpensesIndex from '@/pages/recurring-expenses/index';
import personal from '@/routes/personal';

/** The member's recurring personal bills, a tab of the personal bills. */
export default function PersonalRecurring(
    props: ComponentProps<typeof RecurringExpensesIndex>,
) {
    return <RecurringExpensesIndex {...props} />;
}

PersonalRecurring.layout = {
    breadcrumbs: [
        { title: 'Finanças pessoais', href: personal.index() },
        { title: 'Contas', href: personal.bills() },
        { title: 'Recorrentes', href: personal.bills.recurring() },
    ],
};
