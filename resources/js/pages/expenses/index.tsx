import { Head, Link } from '@inertiajs/react';
import { Pencil, Plus, Receipt, Trash2 } from 'lucide-react';
import { ConfirmDialog } from '@/components/finance/confirm-dialog';
import { EmptyState } from '@/components/finance/empty-state';
import { FilterSelect } from '@/components/finance/filter-select';
import { ExpenseFormDialog } from '@/components/finance/expense-form-dialog';
import { Money } from '@/components/finance/money';
import { MonthNavigator } from '@/components/finance/month-navigator';
import { OccurrenceDetails } from '@/components/finance/occurrence-details';
import { PageHeader } from '@/components/finance/page-header';
import { Pagination } from '@/components/finance/pagination';
import { PaymentActions } from '@/components/finance/payment-actions';
import { StatusBadge } from '@/components/finance/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useQueryFilters } from '@/hooks/use-query-filters';
import { formatDate, formatMonth } from '@/lib/format';
import { cn } from '@/lib/utils';
import expenses from '@/routes/expenses';
import type {
    Category,
    HouseholdMonthSummary,
    Member,
    Occurrence,
    Paginated,
    Scope,
} from '@/types';

type Filters = {
    month: string;
    scope: Scope;
    category: number | null;
    creator: number | null;
    status: 'paid' | 'pending' | 'overdue' | null;
    source: 'one_off' | 'fixed' | 'temporary' | 'credit_card' | null;
};

type Props = {
    filters: Filters;
    occurrences: Paginated<Occurrence>;
    totals: {
        total_cents: number;
        paid_cents: number;
        pending_cents: number;
        members: Record<number, number>;
    };
    summary: HouseholdMonthSummary;
    members: Member[];
    categories: Category[];
};

export default function ExpensesIndex({
    filters,
    occurrences,
    totals,
    members,
    categories,
}: Props) {
    const apply = useQueryFilters(expenses.index.url(), filters);
    const isHousehold = filters.scope === 'household';
    const hasFilters =
        filters.category !== null ||
        filters.creator !== null ||
        filters.status !== null ||
        filters.source !== null;

    return (
        <>
            <Head title="Despesas da casa" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title={
                        isHousehold
                            ? 'Despesas da casa'
                            : 'Minhas despesas pessoais'
                    }
                    description={
                        isHousehold
                            ? 'Contas compartilhadas, divididas pela proporção dos salários.'
                            : 'Contas só suas, com vencimento e situação de pagamento.'
                    }
                >
                    <MonthNavigator
                        month={filters.month}
                        onChange={(month) => apply({ month })}
                    />
                    <ExpenseFormDialog
                        categories={categories}
                        month={filters.month}
                        scope={filters.scope}
                        trigger={
                            <Button>
                                <Plus />
                                Nova despesa
                            </Button>
                        }
                    />
                </PageHeader>

                <nav
                    aria-label="Tipo de despesa"
                    className="inline-flex w-fit rounded-lg bg-muted p-1 text-sm"
                >
                    {(
                        [
                            ['household', 'Casa'],
                            ['personal', 'Pessoais'],
                        ] as const
                    ).map(([scope, label]) => (
                        <Link
                            key={scope}
                            href={expenses.index({
                                query: { month: filters.month, scope },
                            })}
                            preserveScroll
                            aria-current={
                                filters.scope === scope ? 'page' : undefined
                            }
                            className={cn(
                                'rounded-md px-4 py-1.5 font-medium transition-colors',
                                filters.scope === scope
                                    ? 'bg-background shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {label}
                        </Link>
                    ))}
                </nav>

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                    <FilterSelect
                        id="filter-category"
                        label="Categoria"
                        value={filters.category ?? ''}
                        onChange={(value) =>
                            apply({ category: value ? Number(value) : null })
                        }
                        options={[
                            ['', 'Todas'],
                            ...categories.map(
                                (category) =>
                                    [String(category.id), category.name] as [
                                        string,
                                        string,
                                    ],
                            ),
                        ]}
                    />
                    {isHousehold && (
                        <FilterSelect
                            id="filter-creator"
                            label="Criado por"
                            value={filters.creator ?? ''}
                            onChange={(value) =>
                                apply({ creator: value ? Number(value) : null })
                            }
                            options={[
                                ['', 'Todos'],
                                ...members.map(
                                    (member) =>
                                        [
                                            String(member.user_id),
                                            member.first_name,
                                        ] as [string, string],
                                ),
                            ]}
                        />
                    )}
                    <FilterSelect
                        id="filter-status"
                        label="Situação"
                        value={filters.status ?? ''}
                        onChange={(value) =>
                            apply({
                                status: (value || null) as Filters['status'],
                            })
                        }
                        options={[
                            ['', 'Todas'],
                            ['pending', 'Pendentes'],
                            ['overdue', 'Vencidas'],
                            ['paid', 'Pagas'],
                        ]}
                    />
                    <FilterSelect
                        id="filter-source"
                        label="Origem"
                        value={filters.source ?? ''}
                        onChange={(value) =>
                            apply({
                                source: (value || null) as Filters['source'],
                            })
                        }
                        options={[
                            ['', 'Todas'],
                            ['fixed', 'Fixas recorrentes'],
                            ['temporary', 'Extras temporárias'],
                            ['credit_card', 'Parcelas de cartão'],
                            ['one_off', 'Avulsas'],
                        ]}
                    />
                    {hasFilters && (
                        <div className="flex items-end">
                            <Button
                                variant="ghost"
                                onClick={() =>
                                    apply({
                                        category: null,
                                        creator: null,
                                        status: null,
                                        source: null,
                                    })
                                }
                            >
                                Limpar filtros
                            </Button>
                        </div>
                    )}
                </div>

                {isHousehold && (
                    <p className="text-xs text-muted-foreground">
                        <strong>Total</strong> é o valor da conta;{' '}
                        <strong>Parte de cada um</strong> é a responsabilidade
                        proporcional ao salário; <strong>Pagamento</strong>{' '}
                        mostra quem confirmou — sempre o membro que não
                        cadastrou a despesa.
                    </p>
                )}

                {occurrences.data.length === 0 ? (
                    <EmptyState
                        icon={Receipt}
                        title={`Nenhuma despesa em ${formatMonth(filters.month)}`}
                        description={
                            hasFilters
                                ? 'Nenhuma despesa corresponde aos filtros selecionados.'
                                : 'Cadastre uma despesa avulsa ou configure despesas recorrentes.'
                        }
                    />
                ) : (
                    <>
                        <Card className="hidden py-0 md:block">
                            <CardContent className="px-0">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="pl-4">
                                                Despesa
                                            </TableHead>
                                            <TableHead>Categoria</TableHead>
                                            <TableHead className="text-right">
                                                Total
                                            </TableHead>
                                            {isHousehold &&
                                                members.map((member) => (
                                                    <TableHead
                                                        key={member.user_id}
                                                        className="text-right"
                                                    >
                                                        Parte de{' '}
                                                        {member.first_name}
                                                    </TableHead>
                                                ))}
                                            <TableHead>Vencimento</TableHead>
                                            {isHousehold && (
                                                <TableHead>
                                                    Criado por
                                                </TableHead>
                                            )}
                                            <TableHead>Pagamento</TableHead>
                                            <TableHead>Situação</TableHead>
                                            <TableHead className="pr-4 text-right">
                                                <span className="sr-only">
                                                    Ações
                                                </span>
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {occurrences.data.map((occurrence) => (
                                            <TableRow key={occurrence.id}>
                                                <TableCell className="pl-4">
                                                    <ExpenseTitle
                                                        occurrence={occurrence}
                                                    />
                                                </TableCell>
                                                <TableCell className="text-muted-foreground">
                                                    {occurrence.category
                                                        ?.name ?? '—'}
                                                </TableCell>
                                                <TableCell className="text-right font-medium">
                                                    <Money
                                                        cents={
                                                            occurrence.amount_cents
                                                        }
                                                    />
                                                </TableCell>
                                                {isHousehold &&
                                                    members.map((member) => (
                                                        <TableCell
                                                            key={member.user_id}
                                                            className="text-right"
                                                        >
                                                            <Money
                                                                cents={
                                                                    occurrence.shares.find(
                                                                        (
                                                                            share,
                                                                        ) =>
                                                                            share.user_id ===
                                                                            member.user_id,
                                                                    )
                                                                        ?.amount_cents ??
                                                                    0
                                                                }
                                                            />
                                                        </TableCell>
                                                    ))}
                                                <TableCell>
                                                    {formatDate(
                                                        occurrence.due_date,
                                                    )}
                                                </TableCell>
                                                {isHousehold && (
                                                    <TableCell>
                                                        {
                                                            occurrence.created_by.name.split(
                                                                ' ',
                                                            )[0]
                                                        }
                                                    </TableCell>
                                                )}
                                                <TableCell>
                                                    <PaymentActions
                                                        occurrence={occurrence}
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        status={
                                                            occurrence.status
                                                        }
                                                        overdue={
                                                            occurrence.is_overdue
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell className="pr-4">
                                                    <RowActions
                                                        occurrence={occurrence}
                                                        members={members}
                                                        categories={categories}
                                                    />
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                    <TableFooter>
                                        <TableRow>
                                            <TableCell
                                                className="pl-4"
                                                colSpan={2}
                                            >
                                                Total{' '}
                                                {hasFilters && 'filtrado '}
                                                do mês
                                                <span className="block text-xs font-normal text-muted-foreground">
                                                    Pago{' '}
                                                    <Money
                                                        cents={
                                                            totals.paid_cents
                                                        }
                                                    />{' '}
                                                    · pendente{' '}
                                                    <Money
                                                        cents={
                                                            totals.pending_cents
                                                        }
                                                    />
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Money
                                                    cents={totals.total_cents}
                                                />
                                            </TableCell>
                                            {isHousehold &&
                                                members.map((member) => (
                                                    <TableCell
                                                        key={member.user_id}
                                                        className="text-right"
                                                    >
                                                        <Money
                                                            cents={
                                                                totals.members[
                                                                    member
                                                                        .user_id
                                                                ] ?? 0
                                                            }
                                                        />
                                                    </TableCell>
                                                ))}
                                            <TableCell
                                                colSpan={isHousehold ? 5 : 4}
                                            />
                                        </TableRow>
                                    </TableFooter>
                                </Table>
                            </CardContent>
                        </Card>

                        <div className="space-y-3 md:hidden">
                            {occurrences.data.map((occurrence) => (
                                <Card key={occurrence.id} className="py-4">
                                    <CardContent className="space-y-3 px-4">
                                        <div className="flex items-start justify-between gap-2">
                                            <ExpenseTitle
                                                occurrence={occurrence}
                                            />
                                            <StatusBadge
                                                status={occurrence.status}
                                                overdue={occurrence.is_overdue}
                                            />
                                        </div>
                                        <dl className="grid grid-cols-2 gap-2 text-sm">
                                            <dt className="text-muted-foreground">
                                                Total
                                            </dt>
                                            <dd className="text-right font-medium">
                                                <Money
                                                    cents={
                                                        occurrence.amount_cents
                                                    }
                                                />
                                            </dd>
                                            {isHousehold &&
                                                members.map((member) => (
                                                    <ShareRow
                                                        key={member.user_id}
                                                        label={`Parte de ${member.first_name}`}
                                                        cents={
                                                            occurrence.shares.find(
                                                                (share) =>
                                                                    share.user_id ===
                                                                    member.user_id,
                                                            )?.amount_cents ?? 0
                                                        }
                                                    />
                                                ))}
                                            <dt className="text-muted-foreground">
                                                Vencimento
                                            </dt>
                                            <dd className="text-right">
                                                {formatDate(
                                                    occurrence.due_date,
                                                )}
                                            </dd>
                                        </dl>
                                        <div className="flex items-center justify-between gap-2">
                                            <PaymentActions
                                                occurrence={occurrence}
                                            />
                                            <RowActions
                                                occurrence={occurrence}
                                                members={members}
                                                categories={categories}
                                            />
                                        </div>
                                    </CardContent>
                                </Card>
                            ))}
                            <Card className="py-4">
                                <CardContent className="flex justify-between px-4 text-sm font-medium">
                                    <span>Total do mês</span>
                                    <Money cents={totals.total_cents} />
                                </CardContent>
                            </Card>
                        </div>

                        <Pagination page={occurrences} />
                    </>
                )}
            </div>
        </>
    );
}

function ShareRow({ label, cents }: { label: string; cents: number }) {
    return (
        <>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right">
                <Money cents={cents} />
            </dd>
        </>
    );
}

function ExpenseTitle({ occurrence }: { occurrence: Occurrence }) {
    return (
        <div className="min-w-0">
            <div className="font-medium">{occurrence.title}</div>
            <div className="mt-0.5 flex flex-wrap gap-1">
                <Badge variant="outline">{occurrence.source_label}</Badge>
                {occurrence.installment_number && (
                    <Badge variant="outline">
                        {occurrence.installment_number}/
                        {occurrence.installment_count}
                        {occurrence.credit_card_name &&
                            ` · ${occurrence.credit_card_name}`}
                    </Badge>
                )}
                {occurrence.is_customized && (
                    <Badge variant="secondary">Ajustada no mês</Badge>
                )}
            </div>
        </div>
    );
}

function RowActions({
    occurrence,
    members,
    categories,
}: {
    occurrence: Occurrence;
    members: Member[];
    categories: Category[];
}) {
    return (
        <div className="flex items-center justify-end gap-0.5">
            <OccurrenceDetails occurrence={occurrence} members={members} />
            {occurrence.can.update && (
                <ExpenseFormDialog
                    occurrence={occurrence}
                    categories={categories}
                    month={occurrence.billing_month}
                    trigger={
                        <Button
                            size="icon"
                            variant="ghost"
                            className="size-8"
                            aria-label={`Editar ${occurrence.title}`}
                        >
                            <Pencil className="size-4" />
                        </Button>
                    }
                />
            )}
            {occurrence.can.delete && (
                <ConfirmDialog
                    url={expenses.destroy.url(occurrence.id)}
                    title="Remover despesa?"
                    description={
                        occurrence.source === 'recurring'
                            ? `“${occurrence.title}” será removida apenas deste mês e não será gerada de novo. Os demais meses não mudam.`
                            : `“${occurrence.title}” será removida. Esta ação fica registrada no histórico.`
                    }
                    confirmLabel="Remover"
                    trigger={
                        <Button
                            size="icon"
                            variant="ghost"
                            className="size-8 text-destructive"
                            aria-label={`Remover ${occurrence.title}`}
                        >
                            <Trash2 className="size-4" />
                        </Button>
                    }
                />
            )}
        </div>
    );
}

ExpensesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Despesas da casa',
            href: expenses.index(),
        },
    ],
};
