import { Head, Link, router } from '@inertiajs/react';
import { Money } from '@/components/finance/money';
import { YearNavigator } from '@/components/finance/month-navigator';
import { PageHeader } from '@/components/finance/page-header';
import { StatCard } from '@/components/finance/stat-card';
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
import { capitalize, formatMonth, formatPercent } from '@/lib/format';
import expenses from '@/routes/expenses';
import reports from '@/routes/reports';
import type { Member, MemberTotals, PersonalYearSummary } from '@/types';

type Props = {
    year: number;
    members: Member[];
    household: {
        months: {
            month: string;
            total_cents: number;
            paid_cents: number;
            pending_cents: number;
            members: Record<number, number>;
        }[];
        total_cents: number;
        paid_cents: number;
        pending_cents: number;
        by_member: MemberTotals[];
    };
    personal: PersonalYearSummary;
};

export default function ReportsIndex({
    year,
    members,
    household,
    personal,
}: Props) {
    const max = Math.max(1, ...household.months.map((row) => row.total_cents));

    return (
        <>
            <Head title="Relatórios" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Relatórios"
                    description="Resumo anual calculado a partir dos lançamentos registrados. Despesas pagas mantêm a divisão do momento do pagamento."
                >
                    <YearNavigator
                        year={year}
                        onChange={(next) =>
                            router.get(
                                reports.index.url(),
                                { year: next },
                                { preserveScroll: true, preserveState: true },
                            )
                        }
                    />
                </PageHeader>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        title={`Despesas da casa em ${year}`}
                        value={<Money cents={household.total_cents} />}
                        hint={
                            <>
                                Pago <Money cents={household.paid_cents} /> ·
                                pendente{' '}
                                <Money cents={household.pending_cents} />
                            </>
                        }
                    />
                    {members.map((member) => {
                        const totals = household.by_member.find(
                            (row) => row.user_id === member.user_id,
                        );

                        return (
                            <StatCard
                                key={member.user_id}
                                title={`Parte de ${member.first_name} no ano`}
                                value={
                                    <Money cents={totals?.total_cents ?? 0} />
                                }
                                hint={
                                    household.total_cents > 0
                                        ? `${formatPercent(Math.round(((totals?.total_cents ?? 0) * 10000) / household.total_cents))} do total da casa`
                                        : undefined
                                }
                            />
                        );
                    })}
                    <StatCard
                        title="Seu saldo ao fim do ano"
                        value={
                            <Money
                                cents={personal.closing_balance_cents}
                                signed
                            />
                        }
                        hint={
                            <>
                                Fluxo líquido do ano{' '}
                                <Money
                                    cents={personal.totals.net_cents}
                                    signed
                                />
                            </>
                        }
                    />
                </div>

                <Card className="py-0">
                    <CardHeader className="pt-6">
                        <CardTitle>Despesas da casa por mês</CardTitle>
                        <CardDescription>
                            Total das contas e a parte proporcional de cada
                            membro
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="px-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="pl-4">Mês</TableHead>
                                    <TableHead className="text-right">
                                        Total
                                    </TableHead>
                                    <TableHead className="hidden w-40 md:table-cell">
                                        <span className="sr-only">Gráfico</span>
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Pago
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Pendente
                                    </TableHead>
                                    {members.map((member) => (
                                        <TableHead
                                            key={member.user_id}
                                            className="text-right last:pr-4"
                                        >
                                            {member.first_name}
                                        </TableHead>
                                    ))}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {household.months.map((row) => (
                                    <TableRow key={row.month}>
                                        <TableCell className="pl-4">
                                            <Link
                                                href={expenses.index({
                                                    query: { month: row.month },
                                                })}
                                                className="hover:underline"
                                            >
                                                {capitalize(
                                                    formatMonth(row.month),
                                                )}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="text-right font-medium">
                                            <Money cents={row.total_cents} />
                                        </TableCell>
                                        <TableCell className="hidden md:table-cell">
                                            <div className="h-2 overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className="h-full bg-primary/70"
                                                    style={{
                                                        width: `${Math.round((row.total_cents / max) * 100)}%`,
                                                    }}
                                                />
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right text-emerald-700 dark:text-emerald-400">
                                            <Money cents={row.paid_cents} />
                                        </TableCell>
                                        <TableCell className="text-right text-amber-700 dark:text-amber-400">
                                            <Money cents={row.pending_cents} />
                                        </TableCell>
                                        {members.map((member) => (
                                            <TableCell
                                                key={member.user_id}
                                                className="text-right last:pr-4"
                                            >
                                                <Money
                                                    cents={
                                                        row.members[
                                                            member.user_id
                                                        ] ?? 0
                                                    }
                                                />
                                            </TableCell>
                                        ))}
                                    </TableRow>
                                ))}
                            </TableBody>
                            <TableFooter>
                                <TableRow>
                                    <TableCell className="pl-4">
                                        Total {year}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Money cents={household.total_cents} />
                                    </TableCell>
                                    <TableCell className="hidden md:table-cell" />
                                    <TableCell className="text-right">
                                        <Money cents={household.paid_cents} />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Money
                                            cents={household.pending_cents}
                                        />
                                    </TableCell>
                                    {members.map((member) => (
                                        <TableCell
                                            key={member.user_id}
                                            className="text-right last:pr-4"
                                        >
                                            <Money
                                                cents={
                                                    household.by_member.find(
                                                        (row) =>
                                                            row.user_id ===
                                                            member.user_id,
                                                    )?.total_cents ?? 0
                                                }
                                            />
                                        </TableCell>
                                    ))}
                                </TableRow>
                            </TableFooter>
                        </Table>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Suas finanças em {year}</CardTitle>
                        <CardDescription>
                            Visível somente para você
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <dl className="space-y-2 text-sm">
                            {(
                                [
                                    ['Receitas', personal.totals.income_cents],
                                    [
                                        'Despesas lançadas',
                                        personal.totals.ledger_expense_cents,
                                    ],
                                    [
                                        'Contas pessoais',
                                        personal.totals.personal_bills_cents,
                                    ],
                                    [
                                        'Sua parte da casa',
                                        personal.totals.household_share_cents,
                                    ],
                                    [
                                        'Investimentos',
                                        personal.totals.investment_cents,
                                    ],
                                ] as const
                            ).map(([label, cents]) => (
                                <div
                                    key={label}
                                    className="flex justify-between gap-2"
                                >
                                    <dt className="text-muted-foreground">
                                        {label}
                                    </dt>
                                    <dd>
                                        <Money cents={cents} />
                                    </dd>
                                </div>
                            ))}
                            <div className="flex justify-between gap-2 border-t pt-2 font-medium">
                                <dt>Fluxo líquido</dt>
                                <dd>
                                    <Money
                                        cents={personal.totals.net_cents}
                                        signed
                                    />
                                </dd>
                            </div>
                            <div className="flex justify-between gap-2 font-medium">
                                <dt>Saldo disponível em dezembro</dt>
                                <dd>
                                    <Money
                                        cents={personal.closing_balance_cents}
                                        signed
                                    />
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

ReportsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Relatórios',
            href: reports.index(),
        },
    ],
};
