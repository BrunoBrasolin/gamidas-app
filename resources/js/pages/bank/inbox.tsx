import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowDownLeft,
    ArrowUpRight,
    Check,
    CheckCheck,
    Inbox,
    Landmark,
    Sparkles,
    Undo2,
} from 'lucide-react';
import { useState } from 'react';
import { CatalogDialog } from '@/components/bank/catalog-dialog';
import { ConfirmDialog } from '@/components/finance/confirm-dialog';
import { EmptyState } from '@/components/finance/empty-state';
import { FilterSelect } from '@/components/finance/filter-select';
import { Money } from '@/components/finance/money';
import { PageHeader } from '@/components/finance/page-header';
import { Pagination } from '@/components/finance/pagination';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useQueryFilters } from '@/hooks/use-query-filters';
import {
    formatDate,
    formatMoney,
    formatMonth,
    formatShortDate,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import bank from '@/routes/bank';
import expenses from '@/routes/expenses';
import personal from '@/routes/personal';
import type { BankTransaction, Paginated } from '@/types';

type Filters = {
    status: 'pending' | 'catalogued' | 'ignored' | 'removed' | 'all';
    account: number | null;
    channel: 'pix' | 'card' | 'transfer' | 'other' | null;
    month: string | null;
    suggestion: 'with' | 'without' | null;
};

type Props = {
    filters: Filters;
    accounts: { id: number; name: string; type: 'BANK' | 'CREDIT' }[];
    transactions: Paginated<BankTransaction>;
    counts: { pending: number; removed: number };
    hasConnections: boolean;
};

export default function BankInbox({
    filters,
    accounts,
    transactions,
    counts,
    hasConnections,
}: Props) {
    const apply = useQueryFilters(bank.inbox.url(), filters);
    const suggested = transactions.data.filter(
        (transaction) => transaction.suggestion !== null,
    ).length;
    const hasFilters =
        filters.account !== null ||
        filters.channel !== null ||
        filters.month !== null ||
        filters.suggestion !== null;

    return (
        <>
            <Head title="Caixa de entrada" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Caixa de entrada"
                    description="Transações dos seus bancos para catalogar: despesa da casa, pessoal, receita, investimento — ou ignorar. O que você decide vira regra para as próximas."
                >
                    {filters.status === 'pending' && suggested > 0 && (
                        <ConfirmDialog
                            method="post"
                            destructive={false}
                            url={bank.inbox.confirmAll.url()}
                            title="Confirmar todas as sugestões?"
                            description="Cada transação com sugestão é catalogada como sugerido (até 200 de uma vez). As sem sugestão continuam aqui. Dá para desfazer uma a uma."
                            confirmLabel="Confirmar sugestões"
                            trigger={
                                <Button>
                                    <CheckCheck />
                                    Confirmar sugestões
                                </Button>
                            }
                        />
                    )}
                </PageHeader>

                {!hasConnections ? (
                    <EmptyState
                        icon={Landmark}
                        title="Nenhum banco conectado"
                        description="Conecte sua conta e cartão pelo Open Finance para as transações chegarem aqui sozinhas."
                    >
                        <Button asChild>
                            <Link href={bank.index()}>Conectar banco</Link>
                        </Button>
                    </EmptyState>
                ) : (
                    <>
                        <nav
                            aria-label="Situação"
                            className="inline-flex w-fit flex-wrap rounded-lg bg-muted p-1 text-sm"
                        >
                            {(
                                [
                                    [
                                        'pending',
                                        `Para catalogar (${counts.pending})`,
                                    ],
                                    ['catalogued', 'Catalogadas'],
                                    ['ignored', 'Ignoradas'],
                                    ['all', 'Todas'],
                                ] as const
                            ).map(([status, label]) => (
                                <button
                                    key={status}
                                    type="button"
                                    onClick={() =>
                                        apply({
                                            status,
                                            suggestion:
                                                status === 'pending'
                                                    ? filters.suggestion
                                                    : null,
                                        })
                                    }
                                    aria-current={
                                        filters.status === status
                                            ? 'page'
                                            : undefined
                                    }
                                    className={cn(
                                        'rounded-md px-3 py-1.5 font-medium transition-colors',
                                        filters.status === status
                                            ? 'bg-background shadow-sm'
                                            : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {label}
                                </button>
                            ))}
                        </nav>

                        {counts.removed > 0 && filters.status !== 'removed' && (
                            <Alert>
                                <AlertTriangle />
                                <AlertTitle>
                                    {counts.removed === 1
                                        ? '1 transação catalogada foi removida pelo banco'
                                        : `${counts.removed} transações catalogadas foram removidas pelo banco`}
                                </AlertTitle>
                                <AlertDescription>
                                    <p>
                                        As despesas criadas continuam. Confira
                                        se a compra foi cancelada ou estornada.{' '}
                                        <button
                                            type="button"
                                            className="font-medium underline underline-offset-4"
                                            onClick={() =>
                                                apply({ status: 'removed' })
                                            }
                                        >
                                            Ver transações removidas
                                        </button>
                                    </p>
                                </AlertDescription>
                            </Alert>
                        )}

                        <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                            <FilterSelect
                                id="filter-account"
                                label="Conta"
                                value={filters.account ?? ''}
                                onChange={(value) =>
                                    apply({
                                        account: value ? Number(value) : null,
                                    })
                                }
                                options={[
                                    ['', 'Todas'],
                                    ...accounts.map(
                                        (account) =>
                                            [
                                                String(account.id),
                                                account.name,
                                            ] as [string, string],
                                    ),
                                ]}
                            />
                            <FilterSelect
                                id="filter-channel"
                                label="Tipo"
                                value={filters.channel ?? ''}
                                onChange={(value) =>
                                    apply({
                                        channel: (value ||
                                            null) as Filters['channel'],
                                    })
                                }
                                options={[
                                    ['', 'Todos'],
                                    ['pix', 'PIX'],
                                    ['card', 'Cartão de crédito'],
                                    ['transfer', 'Transferência e boleto'],
                                    ['other', 'Outros'],
                                ]}
                            />
                            <div className="grid gap-1.5">
                                <Label htmlFor="filter-month">Mês</Label>
                                <Input
                                    id="filter-month"
                                    type="month"
                                    value={filters.month ?? ''}
                                    onChange={(event) =>
                                        apply({
                                            month: event.target.value || null,
                                        })
                                    }
                                />
                            </div>
                            {filters.status === 'pending' && (
                                <FilterSelect
                                    id="filter-suggestion"
                                    label="Sugestão"
                                    value={filters.suggestion ?? ''}
                                    onChange={(value) =>
                                        apply({
                                            suggestion: (value ||
                                                null) as Filters['suggestion'],
                                        })
                                    }
                                    options={[
                                        ['', 'Todas'],
                                        ['with', 'Com sugestão'],
                                        ['without', 'Sem sugestão'],
                                    ]}
                                />
                            )}
                            {hasFilters && (
                                <div className="flex items-end">
                                    <Button
                                        variant="ghost"
                                        onClick={() =>
                                            apply({
                                                account: null,
                                                channel: null,
                                                month: null,
                                                suggestion: null,
                                            })
                                        }
                                    >
                                        Limpar filtros
                                    </Button>
                                </div>
                            )}
                        </div>

                        {transactions.data.length === 0 ? (
                            <EmptyState
                                icon={Inbox}
                                title={
                                    filters.status === 'pending' && !hasFilters
                                        ? 'Tudo catalogado'
                                        : 'Nenhuma transação'
                                }
                                description={
                                    filters.status === 'pending' && !hasFilters
                                        ? 'As próximas transações dos seus bancos aparecem aqui assim que chegarem.'
                                        : 'Nenhuma transação corresponde aos filtros.'
                                }
                            />
                        ) : (
                            <div className="space-y-3">
                                {transactions.data.map((transaction) => (
                                    <TransactionCard
                                        key={transaction.id}
                                        transaction={transaction}
                                    />
                                ))}
                                <Pagination page={transactions} />
                            </div>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

function TransactionCard({ transaction }: { transaction: BankTransaction }) {
    const outgoing = transaction.direction === 'out';

    return (
        <Card
            className={cn('py-4', transaction.removed && 'border-amber-500/60')}
        >
            <CardContent className="space-y-3 px-4">
                <div className="flex items-start justify-between gap-3">
                    <div className="flex min-w-0 items-start gap-3">
                        <span
                            className={cn(
                                'mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full',
                                outgoing
                                    ? 'bg-red-500/10 text-red-600 dark:text-red-400'
                                    : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
                            )}
                            aria-hidden
                        >
                            {outgoing ? (
                                <ArrowUpRight className="size-4" />
                            ) : (
                                <ArrowDownLeft className="size-4" />
                            )}
                        </span>
                        <div className="min-w-0">
                            <div className="font-medium break-words">
                                {transaction.display_name}
                            </div>
                            {transaction.counterparty_name && (
                                <div className="truncate text-xs text-muted-foreground">
                                    {transaction.description}
                                </div>
                            )}
                            <div className="mt-1 flex flex-wrap gap-1">
                                <Badge variant="outline">
                                    {transaction.channel_label}
                                </Badge>
                                <Badge variant="outline">
                                    {transaction.account.name}
                                </Badge>
                                {transaction.installment_number && (
                                    <Badge variant="outline">
                                        Parcela {transaction.installment_number}
                                        /{transaction.installment_count}
                                    </Badge>
                                )}
                                {transaction.pending_at_bank && (
                                    <Badge variant="secondary">
                                        Fatura aberta
                                    </Badge>
                                )}
                                {transaction.counterparty_document && (
                                    <Badge variant="outline">
                                        {transaction.counterparty_document}
                                    </Badge>
                                )}
                                {transaction.removed && (
                                    <Badge
                                        variant="outline"
                                        className="border-amber-500/60 text-amber-700 dark:text-amber-400"
                                    >
                                        Removida pelo banco
                                    </Badge>
                                )}
                            </div>
                        </div>
                    </div>
                    <div className="shrink-0 text-right">
                        <Money
                            cents={
                                outgoing
                                    ? -transaction.amount_cents
                                    : transaction.amount_cents
                            }
                            signed
                            className="font-semibold"
                        />
                        <div className="text-xs text-muted-foreground">
                            {formatDate(transaction.date)}
                        </div>
                    </div>
                </div>

                {transaction.review_status === 'pending' ? (
                    <PendingActions transaction={transaction} />
                ) : (
                    <ReviewedRow transaction={transaction} />
                )}
            </CardContent>
        </Card>
    );
}

function PendingActions({ transaction }: { transaction: BankTransaction }) {
    const [processing, setProcessing] = useState<'confirm' | 'ignore' | null>(
        null,
    );

    function post(
        url: string,
        data: Record<string, string>,
        kind: 'confirm' | 'ignore',
    ) {
        router.post(url, data, {
            preserveScroll: true,
            onStart: () => setProcessing(kind),
            onFinish: () => setProcessing(null),
        });
    }

    const catalogButton = (
        <Button
            size="sm"
            variant={transaction.suggestion ? 'outline' : 'default'}
        >
            {transaction.suggestion ? 'Ajustar' : 'Catalogar'}
        </Button>
    );

    return (
        <div
            className={cn(
                'flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between',
                transaction.suggestion &&
                    'rounded-md border border-primary/30 bg-primary/5 p-2',
            )}
        >
            {transaction.suggestion ? (
                <p className="flex items-start gap-2 text-sm">
                    <Sparkles
                        className="mt-0.5 size-4 shrink-0 text-primary"
                        aria-hidden
                    />
                    <span>
                        <span className="text-muted-foreground">
                            Sugestão:{' '}
                        </span>
                        <strong>{transaction.suggestion.label}</strong>
                        {transaction.suggestion.rule && (
                            <span className="block text-xs text-muted-foreground">
                                pela regra “{transaction.suggestion.rule.label}”
                                {transaction.suggestion.rule.amount_cents !==
                                    null &&
                                    ` de ${formatMoney(transaction.suggestion.rule.amount_cents)}`}
                            </span>
                        )}
                    </span>
                </p>
            ) : (
                <span className="text-xs text-muted-foreground">
                    {transaction.account.type === 'CREDIT' &&
                    transaction.reference_date !== transaction.date
                        ? `Entra na fatura que vence em ${formatDate(transaction.reference_date)}.`
                        : ''}
                </span>
            )}
            <div className="flex flex-wrap justify-end gap-2">
                {transaction.suggestion &&
                    // A settlement rule with no month adding up still needs
                    // the expenses picked in the dialog.
                    !(
                        transaction.suggestion.action === 'settlement' &&
                        transaction.suggestion.occurrence_ids.length === 0
                    ) && (
                        <Button
                            size="sm"
                            disabled={processing !== null}
                            onClick={() =>
                                post(
                                    bank.transactions.confirm.url(
                                        transaction.id,
                                    ),
                                    {},
                                    'confirm',
                                )
                            }
                        >
                            {processing === 'confirm' ? <Spinner /> : <Check />}
                            Confirmar
                        </Button>
                    )}
                <CatalogDialog
                    transaction={transaction}
                    trigger={catalogButton}
                />
                <Button
                    size="sm"
                    variant="ghost"
                    disabled={processing !== null}
                    onClick={() =>
                        post(
                            bank.transactions.catalog.url(transaction.id),
                            { action: 'ignore', remember: '0' },
                            'ignore',
                        )
                    }
                >
                    {processing === 'ignore' && <Spinner />}
                    Ignorar
                </Button>
            </div>
        </div>
    );
}

function ReviewedRow({ transaction }: { transaction: BankTransaction }) {
    const ignored = transaction.review_status === 'ignored';

    return (
        <div className="flex flex-col gap-2 border-t pt-3 text-sm sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-start gap-2">
                <Check
                    className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    aria-hidden
                />
                <span>
                    {ignored && transaction.offset ? (
                        <>
                            Compensada com “{transaction.offset.display_name}” (
                            {formatShortDate(transaction.offset.date)})
                            <span className="block text-xs text-muted-foreground">
                                Uma anula a outra: nada vira despesa.
                            </span>
                        </>
                    ) : ignored ? (
                        <>
                            Ignorada
                            {transaction.ignore_reason === 'bill_payment' &&
                                ' — pagamento de fatura: as compras já entram pelo cartão'}
                        </>
                    ) : (
                        <>
                            {transaction.catalog_label}
                            <Destination transaction={transaction} />
                        </>
                    )}
                </span>
            </div>
            <ConfirmDialog
                url={bank.transactions.reopen.url(transaction.id)}
                destructive={false}
                title="Desfazer?"
                description={undoDescription(transaction)}
                confirmLabel="Desfazer"
                trigger={
                    <Button size="sm" variant="ghost" className="self-end">
                        <Undo2 />
                        Desfazer
                    </Button>
                }
            />
        </div>
    );
}

function Destination({ transaction }: { transaction: BankTransaction }) {
    const { expense, entry, settlement } = transaction;

    if (settlement) {
        return (
            <>
                {' · '}
                {settlement.count === 1
                    ? '1 despesa paga'
                    : `${settlement.count} despesas pagas`}
                {settlement.months.length > 0 && ' de '}
                {settlement.months.map((month, index) => (
                    <span key={month}>
                        {index > 0 && ', '}
                        <Link
                            href={expenses.index({
                                query: { month, scope: 'household' },
                            })}
                            className="font-medium underline underline-offset-4"
                        >
                            {formatMonth(month)}
                        </Link>
                    </span>
                ))}
            </>
        );
    }

    if (expense) {
        if (expense.missing) {
            return (
                <span className="text-amber-700 dark:text-amber-400">
                    {' '}
                    — a despesa foi removida
                </span>
            );
        }

        return (
            <>
                {' · '}
                <Link
                    href={expenses.index({
                        query: {
                            month: expense.billing_month,
                            scope: expense.scope,
                        },
                    })}
                    className="font-medium underline underline-offset-4"
                >
                    {expense.title}
                </Link>{' '}
                <span className="text-muted-foreground">
                    ({formatMonth(expense.billing_month)}
                    {expense.status === 'paid' ? ', paga' : ', pendente'})
                </span>
            </>
        );
    }

    if (entry) {
        if (entry.missing) {
            return (
                <span className="text-amber-700 dark:text-amber-400">
                    {' '}
                    — o lançamento foi removido
                </span>
            );
        }

        return (
            <>
                {' · '}
                <Link
                    href={personal.transactions.index(entry.slug, {
                        query: { month: entry.month },
                    })}
                    className="font-medium underline underline-offset-4"
                >
                    {entry.title}
                </Link>{' '}
                <span className="text-muted-foreground">
                    ({formatMonth(entry.month)})
                </span>
            </>
        );
    }

    return null;
}

function undoDescription(transaction: BankTransaction): string {
    if (transaction.offset) {
        return `Esta transação e “${transaction.offset.display_name}” deixam de se anular e voltam as duas para a caixa de entrada.`;
    }

    switch (transaction.catalog_type) {
        case 'household':
            return 'A despesa da casa criada a partir desta transação é removida (se ainda estiver pendente) e a transação volta para a caixa de entrada.';
        case 'personal':
        case 'income':
        case 'investment':
            return 'O lançamento pessoal criado a partir desta transação é excluído e a transação volta para a caixa de entrada.';
        case 'linked':
            return 'A transação é desvinculada da despesa e volta para a caixa de entrada. O valor e o pagamento da despesa não mudam.';
        case 'settlement':
            return 'As despesas pagas por este acerto voltam a ficar pendentes (as que o outro membro também acertou pelo banco dele continuam pagas) e a transação volta para a caixa de entrada.';
        default:
            return 'A transação volta para a caixa de entrada.';
    }
}

BankInbox.layout = {
    breadcrumbs: [
        {
            title: 'Caixa de entrada',
            href: bank.inbox(),
        },
    ],
};
