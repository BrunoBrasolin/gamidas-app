import {
    BarChart3,
    CalendarDays,
    Home,
    LayoutGrid,
    Receipt,
    Repeat,
    Wallet,
} from 'lucide-react';
import { dashboard } from '@/routes';
import calendar from '@/routes/calendar';
import expenses from '@/routes/expenses';
import household from '@/routes/household';
import personal from '@/routes/personal';
import recurringExpenses from '@/routes/recurring-expenses';
import reports from '@/routes/reports';
import type { NavItem } from '@/types';

export const mainNavItems: NavItem[] = [
    {
        title: 'Painel',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Agenda',
        href: calendar.index(),
        icon: CalendarDays,
        activePrefix: '/dashboard/calendar',
    },
    {
        title: 'Despesas da casa',
        href: expenses.index(),
        icon: Receipt,
    },
    {
        title: 'Despesas recorrentes',
        href: recurringExpenses.index(),
        icon: Repeat,
    },
    {
        title: 'Finanças pessoais',
        href: personal.index(),
        icon: Wallet,
        items: [
            { title: 'Resumo', href: personal.index() },
            { title: 'Receitas', href: personal.transactions.index('income') },
            {
                title: 'Despesas',
                href: personal.transactions.index('expenses'),
            },
            {
                title: 'Investimentos',
                href: personal.transactions.index('investments'),
            },
        ],
    },
    {
        title: 'Relatórios',
        href: reports.index(),
        icon: BarChart3,
    },
    {
        title: 'Configurações da casa',
        href: household.salaries.edit(),
        icon: Home,
        activePrefix: '/household',
    },
];
