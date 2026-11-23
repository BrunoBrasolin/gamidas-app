import {
    BarChart3,
    Brain,
    CalendarDays,
    Clapperboard,
    Dices,
    FolderOpen,
    Gamepad2,
    GraduationCap,
    Inbox,
    Landmark,
    LayoutGrid,
    LibraryBig,
    PiggyBank,
    Receipt,
    Sheet,
    SlidersHorizontal,
    Tags,
    Ticket,
    TrendingUp,
    Wallet,
} from 'lucide-react';
import { dashboard } from '@/routes';
import attention from '@/routes/attention';
import bank from '@/routes/bank';
import calendar from '@/routes/calendar';
import complementaryHours from '@/routes/complementary-hours';
import expenses from '@/routes/expenses';
import investments from '@/routes/investments';
import games from '@/routes/games';
import knowledge from '@/routes/knowledge';
import personal from '@/routes/personal';
import reports from '@/routes/reports';
import roulette from '@/routes/roulette';
import shows from '@/routes/shows';
import time from '@/routes/time';
import watched from '@/routes/watched';
import type { NavItem } from '@/types';

export type NavGroup = {
    /** Shown above the group; the first group (home) has none. */
    label?: string;
    items: NavItem[];
};

/**
 * The sidebar, one group per area of the app. Settings (the account and the
 * household's salaries and members) live in the user menu, not here.
 */
export const navGroups: NavGroup[] = [
    {
        items: [
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
        ],
    },
    {
        label: 'Finanças',
        items: [
            {
                // The monthly listing and its "Recorrentes" tab.
                title: 'Despesas da casa',
                href: expenses.index(),
                icon: Receipt,
                activePrefix: '/expenses',
            },
            {
                title: 'Finanças pessoais',
                href: personal.index(),
                icon: Wallet,
                activePrefix: '/personal',
                items: [
                    { title: 'Resumo', href: personal.index() },
                    {
                        title: 'Receitas',
                        href: personal.transactions.index('income'),
                    },
                    {
                        title: 'Despesas',
                        href: personal.transactions.index('expenses'),
                    },
                ],
            },
            {
                // Bank positions, gains and history; the contributions
                // entered by hand are linked from the page.
                title: 'Investimentos',
                href: investments.index(),
                icon: PiggyBank,
                activePrefix: '/investments',
            },
            {
                title: 'Relatórios',
                href: reports.index(),
                icon: BarChart3,
            },
        ],
    },
    {
        // Open Finance: banks connected through Pluggy, the inbox of their
        // transactions and the rules that catalogue them (private to each user).
        label: 'Bancos',
        items: [
            {
                title: 'Caixa de entrada',
                href: bank.inbox(),
                icon: Inbox,
            },
            {
                title: 'Contas e cartões',
                href: bank.index(),
                icon: Landmark,
            },
            {
                title: 'Regras de catalogação',
                href: bank.rules.index(),
                icon: SlidersHorizontal,
                activePrefix: '/bank/rules',
            },
        ],
    },
    {
        // Personal time tracking (private to each user).
        label: 'Tempo',
        items: [
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
        ],
    },
    {
        // The knowledge base and complementary hours (private to each user)
        // and the attention game (shared by the household).
        label: 'Estudos',
        items: [
            {
                title: 'Base de conhecimento',
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
            {
                title: 'Horas complementares',
                href: complementaryHours.index(),
                icon: GraduationCap,
                activePrefix: '/complementary-hours',
            },
            {
                title: 'Jogo da Atenção',
                href: attention.index(),
                icon: Brain,
                activePrefix: '/attention',
            },
        ],
    },
    {
        // Shows, watch history and the roulette are shared by the household;
        // each user's Steam library is private to them.
        label: 'Lazer',
        items: [
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
            {
                title: 'Jogos',
                href: games.index(),
                icon: Gamepad2,
                activePrefix: '/games',
            },
            {
                title: 'Roleta',
                href: roulette.index(),
                icon: Dices,
                activePrefix: '/roulette',
            },
        ],
    },
];

export const allNavItems: NavItem[] = navGroups.flatMap((group) => group.items);
