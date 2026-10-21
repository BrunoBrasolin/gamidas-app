import { Form, Head, router } from '@inertiajs/react';
import { Pencil, Plus, Trash2, Wallet } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/finance/confirm-dialog';
import { EmptyState } from '@/components/finance/empty-state';
import { FormField } from '@/components/finance/form-field';
import { Money } from '@/components/finance/money';
import { MoneyInput } from '@/components/finance/money-input';
import {
    MonthNavigator,
    YearNavigator,
} from '@/components/finance/month-navigator';
import { PageHeader } from '@/components/finance/page-header';
import { Pagination } from '@/components/finance/pagination';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatDateTime, formatMonth, today } from '@/lib/format';
import { cn } from '@/lib/utils';
import personal from '@/routes/personal';
import type { Paginated, TransactionType } from '@/types';

type Transaction = {
    id: number;
    title: string;
    amount_cents: number;
    transaction_date: string;
    notes: string | null;
    created_at: string | null;
    updated_at: string | null;
};

type Filters = {
    period: 'month' | 'year';
    month: string;
};

type Props = {
    type: TransactionType;
    slug: 'income' | 'expenses' | 'investments';
    filters: Filters;
    total_cents: number;
    transactions: Paginated<Transaction>;
};

const COPY: Record<
    TransactionType,
    { title: string; empty: string; description: string; note?: string }
> = {
    income: {
        title: 'Receitas',
        empty: 'Registre uma receita para acompanhar seu fluxo de caixa.',
        description:
            'Salário, distribuição de lucros, renda extra e outras entradas.',
    },
    expense: {
        title: 'Despesas pessoais',
        empty: 'Registre uma despesa para acompanhar seu fluxo de caixa.',
        description: 'Gastos pessoais como academia e educação.',
        note: 'Não lance sua contribuição para as despesas da casa: sua parte proporcional já é somada automaticamente ao resumo pessoal. Contas com vencimento e pagamento podem ser cadastradas em Despesas da casa › Pessoais.',
    },
    investment: {
        title: 'Investimentos',
        empty: 'Registre um investimento para acompanhar quanto você está aplicando.',
        description: 'Valores destinados a investimentos.',
        note: 'Investimentos são uma saída separada: não lance o mesmo valor também como despesa.',
    },
};

export default function PersonalTransactions({
    type,
    slug,
    filters,
    total_cents,
    transactions,
}: Props) {
    const copy = COPY[type];
    const url = personal.transactions.index.url(slug);
    const visit = (changes: Partial<Filters>) => {
        const next = { ...filters, ...changes };

        router.get(
            url,
            {
                period: next.period === 'year' ? 'year' : undefined,
                month: next.month,
            },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    };
    const year = Number(filters.month.slice(0, 4));

    return (
        <>
            <Head title={copy.title} />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader title={copy.title} description={copy.description}>
                    <div className="inline-flex rounded-lg bg-muted p-1 text-sm">
                        {(
                            [
                                ['month', 'Mês'],
                                ['year', 'Ano'],
                            ] as const
                        ).map(([value, label]) => (
                            <button
                                key={value}
                                type="button"
                                aria-pressed={filters.period === value}
                                onClick={() => visit({ period: value })}
                                className={cn(
                                    'rounded-md px-3 py-1.5 font-medium',
                                    filters.period === value
                                        ? 'bg-background shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    {filters.period === 'month' ? (
                        <MonthNavigator
                            month={filters.month}
                            onChange={(month) => visit({ month })}
                        />
                    ) : (
                        <YearNavigator
                            year={year}
                            onChange={(next) =>
                                visit({
                                    month: `${next}-${filters.month.slice(5)}`,
                                })
                            }
                        />
                    )}
                    <TransactionFormDialog
                        type={type}
                        slug={slug}
                        month={filters.month}
                        trigger={
                            <Button>
                                <Plus />
                                Novo lançamento
                            </Button>
                        }
                    />
                </PageHeader>

                {copy.note && (
                    <Alert>
                        <Wallet />
                        <AlertDescription>
                            <p>{copy.note}</p>
                        </AlertDescription>
                    </Alert>
                )}

                <div className="flex flex-wrap items-end justify-end gap-4">
                    <div className="text-right">
                        <div className="text-sm text-muted-foreground">
                            Total{' '}
                            {filters.period === 'year'
                                ? `de ${year}`
                                : `de ${formatMonth(filters.month)}`}
                        </div>
                        <div className="text-2xl font-semibold">
                            <Money cents={total_cents} />
                        </div>
                    </div>
                </div>

                {transactions.data.length === 0 ? (
                    <EmptyState
                        icon={Wallet}
                        title="Nenhum lançamento no período"
                        description={copy.empty}
                    />
                ) : (
                    <Card className="py-0">
                        <CardContent className="px-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="pl-4">
                                            Data
                                        </TableHead>
                                        <TableHead>Título</TableHead>
                                        <TableHead className="text-right">
                                            Valor
                                        </TableHead>
                                        <TableHead className="pr-4">
                                            <span className="sr-only">
                                                Ações
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {transactions.data.map((transaction) => (
                                        <TableRow key={transaction.id}>
                                            <TableCell className="pl-4 whitespace-nowrap">
                                                {formatDate(
                                                    transaction.transaction_date,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <div className="font-medium">
                                                    {transaction.title}
                                                </div>
                                                {transaction.notes && (
                                                    <div className="text-xs whitespace-pre-line text-muted-foreground">
                                                        {transaction.notes}
                                                    </div>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right font-medium">
                                                <Money
                                                    cents={
                                                        transaction.amount_cents
                                                    }
                                                />
                                            </TableCell>
                                            <TableCell className="pr-4">
                                                <div className="flex justify-end gap-0.5">
                                                    <TransactionFormDialog
                                                        type={type}
                                                        slug={slug}
                                                        month={filters.month}
                                                        transaction={
                                                            transaction
                                                        }
                                                        trigger={
                                                            <Button
                                                                size="icon"
                                                                variant="ghost"
                                                                className="size-8"
                                                                aria-label={`Editar ${transaction.title}`}
                                                            >
                                                                <Pencil className="size-4" />
                                                            </Button>
                                                        }
                                                    />
                                                    <ConfirmDialog
                                                        url={personal.transactions.destroy.url(
                                                            transaction.id,
                                                        )}
                                                        title="Excluir lançamento?"
                                                        description={`“${transaction.title}” será excluído.`}
                                                        confirmLabel="Excluir"
                                                        trigger={
                                                            <Button
                                                                size="icon"
                                                                variant="ghost"
                                                                className="size-8 text-destructive"
                                                                aria-label={`Excluir ${transaction.title}`}
                                                            >
                                                                <Trash2 className="size-4" />
                                                            </Button>
                                                        }
                                                    />
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                                <TableFooter>
                                    <TableRow>
                                        <TableCell className="pl-4" colSpan={3}>
                                            Total do período
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Money cents={total_cents} />
                                        </TableCell>
                                        <TableCell />
                                    </TableRow>
                                </TableFooter>
                            </Table>
                        </CardContent>
                    </Card>
                )}

                <Pagination page={transactions} />
            </div>
        </>
    );
}

function TransactionFormDialog({
    type,
    slug,
    month,
    transaction,
    trigger,
}: {
    type: TransactionType;
    slug: Props['slug'];
    month: string;
    transaction?: Transaction;
    trigger: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const form = transaction
        ? personal.transactions.update.form(transaction.id)
        : personal.transactions.store.form(slug);
    const now = today();
    const defaultDate = now.startsWith(month) ? now : `${month}-01`;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {transaction ? 'Editar lançamento' : 'Novo lançamento'}
                    </DialogTitle>
                    <DialogDescription>
                        {COPY[type].title}
                        {transaction?.created_at &&
                            ` · criado em ${formatDateTime(transaction.created_at)}`}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...form}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    resetOnSuccess
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <FormField
                                id="transaction-title"
                                label="Título"
                                error={errors.title}
                            >
                                <Input
                                    id="transaction-title"
                                    name="title"
                                    required
                                    maxLength={120}
                                    defaultValue={transaction?.title}
                                />
                            </FormField>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <FormField
                                    id="transaction-amount"
                                    label="Valor"
                                    error={errors.amount}
                                >
                                    <MoneyInput
                                        id="transaction-amount"
                                        name="amount"
                                        required
                                        defaultCents={transaction?.amount_cents}
                                    />
                                </FormField>
                                <FormField
                                    id="transaction-date"
                                    label="Data"
                                    error={errors.transaction_date}
                                >
                                    <Input
                                        id="transaction-date"
                                        name="transaction_date"
                                        type="date"
                                        required
                                        defaultValue={
                                            transaction?.transaction_date ??
                                            defaultDate
                                        }
                                    />
                                </FormField>
                            </div>
                            <FormField
                                id="transaction-notes"
                                label="Observações"
                                error={errors.notes}
                            >
                                <Textarea
                                    id="transaction-notes"
                                    name="notes"
                                    maxLength={2000}
                                    defaultValue={transaction?.notes ?? ''}
                                />
                            </FormField>
                            <DialogFooter>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    Salvar
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

PersonalTransactions.layout = {
    breadcrumbs: [
        {
            title: 'Finanças pessoais',
            href: personal.index(),
        },
    ],
};
