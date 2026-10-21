import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarClock,
    CheckCircle2,
    Home,
    PiggyBank,
    Receipt,
    TrendingDown,
    TrendingUp,
    Users,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { EmptyState } from '@/components/finance/empty-state';
import { Money } from '@/components/finance/money';
import { MonthNavigator } from '@/components/finance/month-navigator';
import { PageHeader } from '@/components/finance/page-header';
import { PaymentActions } from '@/components/finance/payment-actions';
import { StatCard } from '@/components/finance/stat-card';
import { StatusBadge } from '@/components/finance/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    capitalize,
    formatDate,
    formatMonth,
    formatPercent,
} from '@/lib/format';
import { dashboard } from '@/routes';
import expenses from '@/routes/expenses';
import household from '@/routes/household';
import personal from '@/routes/personal';
import recurringExpenses from '@/routes/recurring-expenses';
import type {
    HouseholdMonthSummary,
    Member,
    Occurrence,
    PersonalMonthSummary,
} from '@/types';

type Props = {
    month: string;
    members: Member[];
    combinedSalaryCents: number;
    proportions: Record<number, number>;
    household: HouseholdMonthSummary;
    personal: PersonalMonthSummary;
    upcoming: Occurrence[];
};

export default function Dashboard({
    month,
    members,
    combinedSalaryCents,
    proportions,
    household: summary,
    personal: mine,
    upcoming,
}: Props) {
    const goTo = (next: string) =>
        router.get(
            dashboard.url(),
            { month: next },
            { preserveScroll: true, preserveState: true },
        );

    const paidRatio =
        summary.total_cents > 0
            ? Math.round((summary.paid_cents / summary.total_cents) * 100)
            : 0;

    const sources = [
        {
            label: 'Fixas recorrentes',
            cents: summary.by_source.fixed,
            href: recurringExpenses.index({ query: { kind: 'fixed' } }),
        },
        {
            label: 'Extras temporárias',
            cents: summary.by_source.temporary,
            href: recurringExpenses.index({ query: { kind: 'temporary' } }),
        },
        {
            label: 'Avulsas',
            cents: summary.by_source.one_off,
            href: expenses.index({ query: { month, source: 'one_off' } }),
        },
    ];

    return (
        <>
            <Head title="Painel" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Painel"
                    description={capitalize(formatMonth(month))}
                >
                    <MonthNavigator month={month} onChange={goTo} />
                </PageHeader>

                {combinedSalaryCents === 0 && (
                    <Alert>
                        <AlertTriangle />
                        <AlertTitle>Salários não configurados</AlertTitle>
                        <AlertDescription>
                            <p>
                                Enquanto a renda combinada for zero, as despesas
                                da casa são divididas igualmente.{' '}
                                <Link
                                    href={household.salaries.edit()}
                                    className="underline underline-offset-4"
                                >
                                    Configurar salários
                                </Link>
                            </p>
                        </AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        title="Total da casa no mês"
                        icon={Receipt}
                        value={<Money cents={summary.total_cents} />}
                        hint={`${summary.count} despesa(s) no mês`}
                    />
                    {members.map((member) => {
                        const share = summary.by_member.find(
                            (row) => row.user_id === member.user_id,
                        );

                        return (
                            <StatCard
                                key={member.user_id}
                                title={`Parte de ${member.first_name}`}
                                icon={Users}
                                value={
                                    <Money cents={share?.total_cents ?? 0} />
                                }
                                hint={
                                    <>
                                        {formatPercent(
                                            proportions[member.user_id] ?? 0,
                                        )}{' '}
                                        da renda · pendente{' '}
                                        <Money
                                            cents={share?.pending_cents ?? 0}
                                        />
                                    </>
                                }
                            />
                        );
                    })}
                    <StatCard
                        title="Renda combinada"
                        icon={Wallet}
                        value={<Money cents={combinedSalaryCents} />}
                        hint={members
                            .map(
                                (member) =>
                                    `${member.first_name} ${formatPercent(proportions[member.user_id] ?? 0)}`,
                            )
                            .join(' · ')}
                    />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Pagas e pendentes</CardTitle>
                            <CardDescription>
                                Valor total das contas da casa deste mês
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div
                                className="h-2 overflow-hidden rounded-full bg-muted"
                                role="progressbar"
                                aria-valuenow={paidRatio}
                                aria-valuemin={0}
                                aria-valuemax={100}
                                aria-label="Percentual pago"
                            >
                                <div
                                    className="h-full bg-emerald-500"
                                    style={{ width: `${paidRatio}%` }}
                                />
                            </div>
                            <dl className="grid grid-cols-2 gap-4 text-sm">
                                <div>
                                    <dt className="flex items-center gap-1 text-muted-foreground">
                                        <CheckCircle2 className="size-4 text-emerald-600" />
                                        Pagas ({summary.paid_count})
                                    </dt>
                                    <dd className="text-lg font-semibold">
                                        <Money cents={summary.paid_cents} />
                                    </dd>
                                </div>
                                <div>
                                    <dt className="flex items-center gap-1 text-muted-foreground">
                                        <CalendarClock className="size-4 text-amber-600" />
                                        Pendentes ({summary.pending_count})
                                    </dt>
                                    <dd className="text-lg font-semibold">
                                        <Money cents={summary.pending_cents} />
                                    </dd>
                                </div>
                            </dl>
                            {summary.overdue_count > 0 && (
                                <Link
                                    href={expenses.index({
                                        query: { month, status: 'overdue' },
                                    })}
                                    className="flex items-center gap-1 text-sm font-medium text-red-600 dark:text-red-400"
                                >
                                    <AlertTriangle className="size-4" />
                                    {summary.overdue_count} despesa(s)
                                    vencida(s)
                                </Link>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Composição do mês</CardTitle>
                            <CardDescription>
                                Lançamentos da casa por origem
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <ul className="space-y-2 text-sm">
                                {sources.map((source) => (
                                    <li
                                        key={source.label}
                                        className="flex items-center justify-between gap-2"
                                    >
                                        <Link
                                            href={source.href}
                                            className="hover:underline"
                                        >
                                            {source.label}
                                        </Link>
                                        <Money cents={source.cents} />
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-4 lg:grid-cols-5">
                    <Card className="lg:col-span-3">
                        <CardHeader>
                            <CardTitle>Próximos vencimentos</CardTitle>
                            <CardDescription>
                                Despesas pendentes vencidas ou vencendo nos
                                próximos dias
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {upcoming.length === 0 ? (
                                <EmptyState
                                    icon={CheckCircle2}
                                    title="Tudo em dia"
                                    description="Nenhuma despesa pendente vencendo em breve."
                                />
                            ) : (
                                <ul className="divide-y">
                                    {upcoming.map((occurrence) => (
                                        <li
                                            key={occurrence.id}
                                            className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between"
                                        >
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="truncate font-medium">
                                                        {occurrence.title}
                                                    </span>
                                                    {occurrence.scope ===
                                                        'personal' && (
                                                        <Badge variant="secondary">
                                                            Pessoal
                                                        </Badge>
                                                    )}
                                                    <StatusBadge
                                                        status={
                                                            occurrence.status
                                                        }
                                                        overdue={
                                                            occurrence.is_overdue
                                                        }
                                                    />
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    Vence em{' '}
                                                    {formatDate(
                                                        occurrence.due_date,
                                                    )}{' '}
                                                    ·{' '}
                                                    <Money
                                                        cents={
                                                            occurrence.amount_cents
                                                        }
                                                    />
                                                </div>
                                            </div>
                                            <PaymentActions
                                                occurrence={occurrence}
                                            />
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>

                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>Minhas finanças</CardTitle>
                            <CardDescription>
                                Seu resumo pessoal do mês
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <dl className="space-y-2 text-sm">
                                <SummaryRow
                                    icon={TrendingUp}
                                    label="Receitas"
                                    cents={mine.income_cents}
                                />
                                <SummaryRow
                                    icon={TrendingDown}
                                    label="Despesas (com sua parte da casa)"
                                    cents={mine.expense_cents}
                                />
                                <SummaryRow
                                    icon={PiggyBank}
                                    label="Investimentos"
                                    cents={mine.investment_cents}
                                />
                                <div className="border-t pt-2">
                                    <SummaryRow
                                        icon={Wallet}
                                        label="Fluxo líquido do mês"
                                        cents={mine.net_cents}
                                        signed
                                    />
                                </div>
                                <SummaryRow
                                    icon={Home}
                                    label="Saldo disponível acumulado"
                                    cents={mine.balance_cents}
                                    signed
                                />
                            </dl>
                            <Link
                                href={personal.index({ query: { month } })}
                                className="mt-4 inline-block text-sm font-medium underline underline-offset-4"
                            >
                                Ver finanças pessoais
                            </Link>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

function SummaryRow({
    icon: Icon,
    label,
    cents,
    signed = false,
}: {
    icon: LucideIcon;
    label: string;
    cents: number;
    signed?: boolean;
}) {
    return (
        <div className="flex items-center justify-between gap-2">
            <dt className="flex items-center gap-2 text-muted-foreground">
                <Icon className="size-4" aria-hidden />
                {label}
            </dt>
            <dd className="font-medium">
                <Money cents={cents} signed={signed} />
            </dd>
        </div>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Painel',
            href: dashboard(),
        },
    ],
};
