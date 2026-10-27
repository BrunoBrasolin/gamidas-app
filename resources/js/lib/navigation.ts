import {
    Brain,
    FolderOpen,
    LibraryBig,
    BarChart3,
    CalendarDays,
    Clapperboard,
    GraduationCap,
    Home,
    LayoutGrid,
    Receipt,
    Repeat,
    Sheet,
    Tags,
    Ticket,
    TrendingUp,
    Wallet,
} from 'lucide-react';
import { dashboard } from '@/routes';
import attention from '@/routes/attention';
import calendar from '@/routes/calendar';
import complementaryHours from '@/routes/complementary-hours';
import expenses from '@/routes/expenses';
import household from '@/routes/household';
import personal from '@/routes/personal';
import recurringExpenses from '@/routes/recurring-expenses';
import reports from '@/routes/reports';
import shows from '@/routes/shows';
import knowledge from '@/routes/knowledge';
import time from '@/routes/time';
import watched from '@/routes/watched';
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

/**
 * The personal time tracking module (private to each user).
 */
export const timeNavItems: NavItem[] = [
    {
        title: 'Planilha de horas',
        href: time.index(),
        icon: Sheet,
    },
    {
        title: 'Categorias',
        href: time.categories.index(),
        icon: Tags,
    },
    {
        title: 'Relatórios de tempo',
        href: time.reports.index(),
        icon: TrendingUp,
    },
];

/**
 * Leisure: concerts of the household and what the user watched on Jellyfin
 * (private to each user).
 */
export const showNavItems: NavItem[] = [
    {
        title: 'Shows',
        href: shows.index(),
        icon: Ticket,
        activePrefix: '/shows',
    },
    {
        title: 'Assistidos',
        href: watched.index(),
        icon: Clapperboard,
        activePrefix: '/watched',
    },
];

/**
 * The knowledge base (private to each user).
 */
export const knowledgeNavItems: NavItem[] = [
    {
        title: 'Matérias',
        href: knowledge.index(),
        icon: LibraryBig,
        activePrefix: '/knowledge',
        inactivePrefix: '/knowledge/files',
    },
    {
        title: 'Arquivos',
        href: knowledge.files(),
        icon: FolderOpen,
        activePrefix: '/knowledge/files',
    },
];

/**
 * College complementary hours (private to each user).
 */
export const collegeNavItems: NavItem[] = [
    {
        title: 'Horas complementares',
        href: complementaryHours.index(),
        icon: GraduationCap,
        activePrefix: '/complementary-hours',
    },
];

export const allNavItems: NavItem[] = [
    ...mainNavItems,
    ...timeNavItems,
    ...knowledgeNavItems,
    ...collegeNavItems,
    ...showNavItems,
];
