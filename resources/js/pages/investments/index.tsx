import { Head, Link } from '@inertiajs/react';
import {
    ChartLine,
    Coins,
    Landmark,
    PiggyBank,
    Plus,
    TrendingUp,
    Wallet,
} from 'lucide-react';
import { EmptyState } from '@/components/finance/empty-state';
import { Money } from '@/components/finance/money';
import { PageHeader } from '@/components/finance/page-header';
import { StatCard } from '@/components/finance/stat-card';
import { AllocationBars } from '@/components/investments/allocation-bars';
import type { AllocationSlice } from '@/components/investments/allocation-bars';
import { BalanceChart } from '@/components/investments/balance-chart';
import type { BalancePoint } from '@/components/investments/balance-chart';
import { ContributionsChart } from '@/components/investments/contributions-chart';
import type { Contribution } from '@/components/investments/contributions-chart';
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
import { formatDate, formatPercent } from '@/lib/format';
import { cn } from '@/lib/utils';
import bank from '@/routes/bank';
import investments from '@/routes/investments';
import personal from '@/routes/personal';

type Position = {
    id: number;
    name: string;
    type: string | null;
    type_label: string;
    kind_label: string;
    institution: string;
    balance_cents: number;
    invested_cents: number | null;
    gain_cents: number | null;
    withdrawal_cents: number | null;
    rate: string | null;
    due_date: string | null;
};

type Props = {
    totals: {
        balance_cents: number;
        invested_cents: number;
        gain_cents: number;
        positions_with_gain: number;
        positions: number;
    };
    positions: Position[];
    allocation: AllocationSlice[];
    history: BalancePoint[];
    contributions: Contribution[];
    hasConnections: boolean;
};

/** The gain over what was put in, in basis points (null without a base). */
function gainRatio(gain: number | null, invested: number | null) {
    return gain === null || invested === null || invested <= 0
        ? null
        : Math.round((gain * 10_000) / invested);
}

function Gain({
    gain,
    invested,
    className,
}: {
    gain: number | null;
    invested: number | null;
    className?: string;
}) {
    if (gain === null) {
        return <span className="text-muted-foreground">—</span>;
    }

    const ratio = gainRatio(gain, invested);

    return (
        <span className={cn('inline-flex flex-col items-end', className)}>
            <Money cents={gain} signed />
            {ratio !== null && (
                <span className="text-xs text-muted-foreground tabular-nums">
                    {ratio > 0 && '+'}
                    {formatPercent(ratio)}
                </span>
            )}
        </span>
    );
}

export default function InvestmentsIndex({
    totals,
    positions,
    allocation,
    history,
    contributions,
    hasConnections,
}: Props) {
    const contributed = contributions.reduce(
        (sum, month) => sum + month.amount_cents,
        0,
    );
    const ratio = gainRatio(totals.gain_cents, totals.invested_cents);
    const partial =
        totals.positions_with_gain > 0 &&
        totals.positions_with_gain < totals.positions;

    return (
        <>
            <Head title="Investimentos" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Investimentos"
                    description="Suas posições nos bancos conectados: quanto você aplicou, quanto rendeu e como o patrimônio evolui. Só você vê."
                >
                    <Button variant="outline" asChild>
                        <Link href={personal.transactions.index('investments')}>
                            <Plus />
                            Aportes lançados
                        </Link>
                    </Button>
                    <Button variant="outline" asChild>
                        <Link href={bank.index()}>
                            <Landmark />
                            Contas e cartões
                        </Link>
                    </Button>
                </PageHeader>

                {positions.length === 0 ? (
                    <EmptyState
                        icon={PiggyBank}
                        title={
                            hasConnections
                                ? 'Nenhum investimento nos bancos conectados'
                                : 'Nenhum banco conectado'
                        }
                        description={
                            hasConnections
                                ? 'As posições aparecem aqui quando o banco as compartilha pelo Open Finance (a próxima busca traz o que houver).'
                                : 'Conecte o banco ou corretora pelo Open Finance para ver saldo, rendimento e a evolução dos seus investimentos.'
                        }
                    >
                        {!hasConnections && (
                            <Button asChild>
                                <Link href={bank.index()}>Conectar banco</Link>
                            </Button>
                        )}
                    </EmptyState>
                ) : (
                    <>
                        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                            <StatCard
                                title="Patrimônio investido"
                                icon={Wallet}
                                value={<Money cents={totals.balance_cents} />}
                                hint={
                                    totals.positions === 1
                                        ? '1 posição'
                                        : `${totals.positions} posições`
                                }
                            />
                            <StatCard
                                title="Valor aplicado"
                                icon={Coins}
                                value={
                                    totals.positions_with_gain > 0 ? (
                                        <Money cents={totals.invested_cents} />
                                    ) : (
                                        '—'
                                    )
                                }
                                hint={
                                    totals.positions_with_gain === 0
                                        ? 'O banco não informa o valor aplicado'
                                        : partial
                                          ? `De ${totals.positions_with_gain} das ${totals.positions} posições (as que o banco informa)`
                                          : 'Quanto você colocou'
                                }
                            />
                            <StatCard
                                title="Rendimento"
                                icon={TrendingUp}
                                value={
                                    totals.positions_with_gain > 0 ? (
                                        <Money
                                            cents={totals.gain_cents}
                                            signed
                                        />
                                    ) : (
                                        '—'
                                    )
                                }
                                hint={
                                    ratio !== null
                                        ? `${ratio > 0 ? '+' : ''}${formatPercent(ratio)} sobre o aplicado (bruto)`
                                        : 'Saldo menos o valor aplicado'
                                }
                            />
                            <StatCard
                                title="Aportes em 12 meses"
                                icon={PiggyBank}
                                value={<Money cents={contributed} />}
                                hint="Lançados em Finanças pessoais"
                            />
                        </div>

                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <ChartLine className="size-4" aria-hidden />
                                    Evolução do patrimônio
                                </CardTitle>
                                <CardDescription>
                                    Saldo total a cada dia em que o banco foi
                                    buscado, nos últimos 12 meses.
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                {history.length >= 2 ? (
                                    <BalanceChart points={history} />
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        O histórico começa
                                        {history.length === 1
                                            ? ` em ${formatDate(history[0].date)}`
                                            : ' na próxima busca do banco'}
                                        : a cada dia o saldo é guardado e o
                                        gráfico vai se formando.
                                    </p>
                                )}
                            </CardContent>
                        </Card>

                        <div className="grid gap-6 lg:grid-cols-2">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Por tipo</CardTitle>
                                    <CardDescription>
                                        Quanto do patrimônio está em cada tipo
                                        de investimento.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <AllocationBars slices={allocation} />
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader>
                                    <CardTitle>Aportes por mês</CardTitle>
                                    <CardDescription>
                                        Investimentos lançados em Finanças
                                        pessoais (à mão ou pela caixa de
                                        entrada).
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <ContributionsChart
                                        months={contributions}
                                    />
                                </CardContent>
                            </Card>
                        </div>

                        <Card className="py-0">
                            <CardContent className="px-0">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="pl-4">
                                                Investimento
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Aplicado
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Saldo
                                            </TableHead>
                                            <TableHead className="pr-4 text-right">
                                                Rendimento
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {positions.map((position) => (
                                            <TableRow key={position.id}>
                                                <TableCell className="pl-4 whitespace-normal">
                                                    <div className="font-medium">
                                                        {position.name}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {[
                                                            position.institution,
                                                            position.kind_label,
                                                            position.rate,
                                                            position.due_date &&
                                                                `vence ${formatDate(position.due_date)}`,
                                                        ]
                                                            .filter(Boolean)
                                                            .join(' · ')}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {position.invested_cents !==
                                                    null ? (
                                                        <Money
                                                            cents={
                                                                position.invested_cents
                                                            }
                                                        />
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            —
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Money
                                                        cents={
                                                            position.balance_cents
                                                        }
                                                    />
                                                    {position.withdrawal_cents !==
                                                        null && (
                                                        <div className="text-xs text-muted-foreground">
                                                            líquido{' '}
                                                            <Money
                                                                cents={
                                                                    position.withdrawal_cents
                                                                }
                                                            />
                                                        </div>
                                                    )}
                                                </TableCell>
                                                <TableCell className="pr-4 text-right">
                                                    <Gain
                                                        gain={
                                                            position.gain_cents
                                                        }
                                                        invested={
                                                            position.invested_cents
                                                        }
                                                    />
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                    <TableFooter>
                                        <TableRow>
                                            <TableCell className="pl-4 font-medium">
                                                Total
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {totals.positions_with_gain >
                                                0 ? (
                                                    <Money
                                                        cents={
                                                            totals.invested_cents
                                                        }
                                                    />
                                                ) : (
                                                    '—'
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Money
                                                    cents={totals.balance_cents}
                                                />
                                            </TableCell>
                                            <TableCell className="pr-4 text-right">
                                                <Gain
                                                    gain={
                                                        totals.positions_with_gain >
                                                        0
                                                            ? totals.gain_cents
                                                            : null
                                                    }
                                                    invested={
                                                        totals.invested_cents
                                                    }
                                                />
                                            </TableCell>
                                        </TableRow>
                                    </TableFooter>
                                </Table>
                            </CardContent>
                        </Card>
                    </>
                )}
            </div>
        </>
    );
}

InvestmentsIndex.layout = {
    breadcrumbs: [{ title: 'Investimentos', href: investments.index() }],
};
