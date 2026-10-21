import { Head, Link, router } from '@inertiajs/react';
import {
    Info,
    PiggyBank,
    TrendingDown,
    TrendingUp,
    Wallet,
    Scale,
} from 'lucide-react';
import { Money } from '@/components/finance/money';
import {
    MonthNavigator,
    YearNavigator,
} from '@/components/finance/month-navigator';
import { PageHeader } from '@/components/finance/page-header';
import { StatCard } from '@/components/finance/stat-card';
import { StatusBadge } from '@/components/finance/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { capitalize, formatDate, formatMonth } from '@/lib/format';
import { cn } from '@/lib/utils';
import expenses from '@/routes/expenses';
import personal from '@/routes/personal';
import type {
    OccurrenceStatus,
    PersonalMonthSummary,
    PersonalYearSummary,
} from '@/types';

type Props = {
    view: 'month' | 'year';
    month: string;
    year: number;
    summary: PersonalMonthSummary | null;
    yearly: PersonalYearSummary | null;
    bills: {
        id: number;
        title: string;
        due_date: string;
        amount_cents: number;
        status: OccurrenceStatus;
    }[];
};

export default function PersonalIndex({
    view,
    month,
    year,
    summary,
    yearly,
    bills,
}: Props) {
    const visit = (query: Record<string, string | number>) =>
        router.get(personal.index.url(), query, {
            preserveScroll: true,
            preserveState: true,
        });

    const flows = view === 'month' ? summary : yearly?.totals;
    const balance =
        view === 'month'
            ? (summary?.balance_cents ?? 0)
            : (yearly?.closing_balance_cents ?? 0);

    return (
        <>
            <Head title="Finanças pessoais" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Finanças pessoais"
                    description="Visível somente para você."
                >
                    <div className="inline-flex rounded-lg bg-muted p-1 text-sm">
                        {(
                            [
                                ['month', 'Mensal'],
                                ['year', 'Anual'],
                            ] as const
                        ).map(([value, label]) => (
                            <button
                                key={value}
                                type="button"
                                aria-pressed={view === value}
                                onClick={() =>
                                    visit(
                                        value === 'year'
                                            ? { view: 'year', year, month }
                                            : { month },
                                    )
                                }
                                className={cn(
                                    'rounded-md px-3 py-1.5 font-medium',
                                    view === value
                                        ? 'bg-background shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    {view === 'month' ? (
                        <MonthNavigator
                            month={month}
                            onChange={(next) => visit({ month: next })}
                        />
                    ) : (
                        <YearNavigator
                            year={year}
                            onChange={(next) =>
                                visit({ view: 'year', year: next, month })
                            }
                        />
                    )}
                </PageHeader>

                <div className="flex flex-wrap gap-2">
                    <Button asChild variant="outline" size="sm">
                        <Link href={personal.transactions.index('income')}>
                            <TrendingUp />
                            Receitas
                        </Link>
                    </Button>
                    <Button asChild variant="outline" size="sm">
                        <Link href={personal.transactions.index('expenses')}>
                            <TrendingDown />
                            Despesas
                        </Link>
                    </Button>
                    <Button asChild variant="outline" size="sm">
                        <Link href={personal.transactions.index('investments')}>
                            <PiggyBank />
                            Investimentos
                        </Link>
                    </Button>
                </div>

                {flows && (
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                        <StatCard
                            title="Receitas"
                            icon={TrendingUp}
                            value={<Money cents={flows.income_cents} />}
                        />
                        <StatCard
                            title="Despesas"
                            icon={TrendingDown}
                            value={<Money cents={flows.expense_cents} />}
                            hint="Inclui sua parte da casa"
                        />
                        <StatCard
                            title="Investimentos"
                            icon={PiggyBank}
                            value={<Money cents={flows.investment_cents} />}
                            hint="Saída separada, não é despesa"
                        />
                        <StatCard
                            title="Fluxo líquido"
                            icon={Scale}
                            value={<Money cents={flows.net_cents} signed />}
                            hint="Receitas − despesas − investimentos"
                        />
                        <StatCard
                            title="Saldo disponível"
                            icon={Wallet}
                            value={<Money cents={balance} signed />}
                            hint={
                                view === 'month'
                                    ? `Acumulado até o fim de ${formatMonth(month)}`
                                    : `Acumulado até dezembro de ${year}`
                            }
                        />
                    </div>
                )}

                <Alert>
                    <Info />
                    <AlertTitle>Como o cálculo funciona</AlertTitle>
                    <AlertDescription>
                        <p>
                            <strong>Despesas</strong> somam seus lançamentos de
                            despesa, suas contas pessoais (recorrentes e
                            avulsas) e{' '}
                            <strong>sua parte nas despesas da casa</strong>,
                            calculada automaticamente pela proporção dos
                            salários — não lance a contribuição para a casa
                            manualmente. <strong>Investimentos</strong> são uma
                            saída separada e nunca contam como despesa. Os
                            valores entram no mês de referência, pagos ou não. O{' '}
                            <strong>saldo disponível</strong> é o fluxo líquido
                            acumulado de todos os meses até o fim do período.
                        </p>
                    </AlertDescription>
                </Alert>

                {view === 'month' && summary && (
                    <div className="grid gap-4 lg:grid-cols-2">
                        <Card>
                            <CardHeader>
                                <CardTitle>Composição das despesas</CardTitle>
                                <CardDescription>
                                    {capitalize(formatMonth(month))}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <dl className="space-y-2 text-sm">
                                    <BreakdownRow
                                        label="Despesas lançadas"
                                        cents={summary.ledger_expense_cents}
                                    />
                                    <BreakdownRow
                                        label="Contas pessoais"
                                        cents={summary.personal_bills_cents}
                                    />
                                    <BreakdownRow
                                        label="Sua parte da casa"
                                        cents={summary.household_share_cents}
                                    />
                                    <div className="border-t pt-2 font-medium">
                                        <BreakdownRow
                                            label="Total de despesas"
                                            cents={summary.expense_cents}
                                        />
                                    </div>
                                </dl>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Contas pessoais do mês</CardTitle>
                                <CardDescription>
                                    Suas despesas com vencimento e pagamento
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {bills.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Nenhuma conta pessoal neste mês.
                                    </p>
                                ) : (
                                    <ul className="divide-y text-sm">
                                        {bills.map((bill) => (
                                            <li
                                                key={bill.id}
                                                className="flex items-center justify-between gap-2 py-2"
                                            >
                                                <div>
                                                    <div className="font-medium">
                                                        {bill.title}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        vence{' '}
                                                        {formatDate(
                                                            bill.due_date,
                                                        )}
                                                    </div>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <Money
                                                        cents={
                                                            bill.amount_cents
                                                        }
                                                    />
                                                    <StatusBadge
                                                        status={bill.status}
                                                    />
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                <Link
                                    href={expenses.index({
                                        query: { month, scope: 'personal' },
                                    })}
                                    className="mt-3 inline-block text-sm font-medium underline underline-offset-4"
                                >
                                    Gerenciar contas pessoais
                                </Link>
                            </CardContent>
                        </Card>
                    </div>
                )}

                {view === 'year' && yearly && (
                    <Card className="py-0">
                        <CardContent className="px-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="pl-4">
                                            Mês
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Receitas
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Despesas
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Parte da casa
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Investimentos
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Fluxo líquido
                                        </TableHead>
                                        <TableHead className="pr-4 text-right">
                                            Saldo
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow>
                                        <TableCell
                                            className="pl-4 text-muted-foreground"
                                            colSpan={6}
                                        >
                                            Saldo inicial do ano
                                        </TableCell>
                                        <TableCell className="pr-4 text-right">
                                            <Money
                                                cents={
                                                    yearly.opening_balance_cents
                                                }
                                                signed
                                            />
                                        </TableCell>
                                    </TableRow>
                                    {yearly.months.map((row) => (
                                        <TableRow key={row.month}>
                                            <TableCell className="pl-4">
                                                <Link
                                                    href={personal.index({
                                                        query: {
                                                            month: row.month,
                                                        },
                                                    })}
                                                    className="hover:underline"
                                                >
                                                    {capitalize(
                                                        formatMonth(row.month),
                                                    )}
                                                </Link>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Money
                                                    cents={row.income_cents}
                                                />
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Money
                                                    cents={row.expense_cents}
                                                />
                                            </TableCell>
                                            <TableCell className="text-right text-muted-foreground">
                                                <Money
                                                    cents={
                                                        row.household_share_cents
                                                    }
                                                />
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Money
                                                    cents={row.investment_cents}
                                                />
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Money
                                                    cents={row.net_cents}
                                                    signed
                                                />
                                            </TableCell>
                                            <TableCell className="pr-4 text-right">
                                                <Money
                                                    cents={row.balance_cents}
                                                    signed
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                                <TableFooter>
                                    <TableRow>
                                        <TableCell className="pl-4">
                                            Total {year}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Money
                                                cents={
                                                    yearly.totals.income_cents
                                                }
                                            />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Money
                                                cents={
                                                    yearly.totals.expense_cents
                                                }
                                            />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Money
                                                cents={
                                                    yearly.totals
                                                        .household_share_cents
                                                }
                                            />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Money
                                                cents={
                                                    yearly.totals
                                                        .investment_cents
                                                }
                                            />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Money
                                                cents={yearly.totals.net_cents}
                                                signed
                                            />
                                        </TableCell>
                                        <TableCell className="pr-4 text-right">
                                            <Money
                                                cents={
                                                    yearly.closing_balance_cents
                                                }
                                                signed
                                            />
                                        </TableCell>
                                    </TableRow>
                                </TableFooter>
                            </Table>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

function BreakdownRow({ label, cents }: { label: string; cents: number }) {
    return (
        <div className="flex justify-between gap-2">
            <dt>{label}</dt>
            <dd>
                <Money cents={cents} />
            </dd>
        </div>
    );
}

PersonalIndex.layout = {
    breadcrumbs: [
        {
            title: 'Finanças pessoais',
            href: personal.index(),
        },
    ],
};
