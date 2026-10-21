import { Form, Head, Link } from '@inertiajs/react';
import {
    ChevronDown,
    CreditCard as CreditCardIcon,
    Pencil,
    Plus,
    ShoppingBag,
    Trash2,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { CategorySelect } from '@/components/finance/category-select';
import { ConfirmDialog } from '@/components/finance/confirm-dialog';
import { EmptyState } from '@/components/finance/empty-state';
import { FilterSelect } from '@/components/finance/filter-select';
import { FormField } from '@/components/finance/form-field';
import { Money } from '@/components/finance/money';
import { MoneyInput } from '@/components/finance/money-input';
import { MonthNavigator } from '@/components/finance/month-navigator';
import { PageHeader } from '@/components/finance/page-header';
import { Pagination } from '@/components/finance/pagination';
import { PaymentActions } from '@/components/finance/payment-actions';
import { ScopeField } from '@/components/finance/scope-field';
import { StatusBadge } from '@/components/finance/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
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
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { useQueryFilters } from '@/hooks/use-query-filters';
import {
    centsToDecimal,
    formatDate,
    formatMoney,
    formatMonth,
    formatShortMonth,
    shiftMonth,
    today,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import creditCardPurchases from '@/routes/credit-card-purchases';
import creditCards from '@/routes/credit-cards';
import type { Category, Member, Occurrence, Paginated, Scope } from '@/types';

type Mode = 'installments' | 'fixed_monthly';

type CardRow = {
    id: number;
    name: string;
    last_four_digits: string | null;
    holder_user_id: number | null;
    holder: string | null;
    due_day: number;
    is_active: boolean;
    purchases_count: number;
};

type Invoice = {
    credit_card_id: number;
    total_cents: number;
    paid_cents: number;
    pending_cents: number;
    count: number;
};

type Purchase = {
    id: number;
    credit_card_id: number;
    credit_card: string;
    scope: Scope;
    description: string;
    notes: string | null;
    category: Category | null;
    purchase_date: string;
    mode: Mode;
    total_amount_cents: number;
    installment_count: number;
    installment_amount_cents: number;
    first_billing_month: string;
    last_billing_month: string;
    paid_count: number;
    paid_cents: number;
    remaining_count: number;
    remaining_cents: number;
    created_by: string;
    created_at: string | null;
    can_change_schedule: boolean;
    installments: Occurrence[];
};

type Filters = {
    month: string;
    card: number | null;
    status: 'open' | 'finished';
};

type Props = {
    filters: Filters;
    cards: CardRow[];
    invoices: Invoice[];
    purchases: Paginated<Purchase>;
    members: Member[];
    categories: Category[];
};

export default function CreditCardsIndex({
    filters,
    cards,
    invoices,
    purchases,
    members,
    categories,
}: Props) {
    const apply = useQueryFilters(creditCards.index.url(), filters);
    const activeCards = cards.filter((card) => card.is_active);

    return (
        <>
            <Head title="Cartões e parcelas" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Cartões e parcelas"
                    description="Compras parceladas e cobranças mensais no cartão. Cada parcela é acompanhada separadamente e entra uma única vez nos totais do mês."
                >
                    <MonthNavigator
                        month={filters.month}
                        onChange={(month) => apply({ month })}
                    />
                    <CardFormDialog
                        members={members}
                        trigger={
                            <Button variant="outline">
                                <CreditCardIcon />
                                Novo cartão
                            </Button>
                        }
                    />
                    {activeCards.length > 0 && (
                        <PurchaseFormDialog
                            cards={activeCards}
                            categories={categories}
                            month={filters.month}
                            trigger={
                                <Button>
                                    <Plus />
                                    Nova compra
                                </Button>
                            }
                        />
                    )}
                </PageHeader>

                {cards.length === 0 ? (
                    <EmptyState
                        icon={CreditCardIcon}
                        title="Nenhum cartão cadastrado"
                        description="Cadastre os cartões de crédito da casa para registrar compras parceladas."
                    />
                ) : (
                    <section
                        aria-label="Faturas do mês"
                        className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
                    >
                        {cards.map((card) => {
                            const invoice = invoices.find(
                                (row) => row.credit_card_id === card.id,
                            );

                            return (
                                <Card
                                    key={card.id}
                                    className={cn(
                                        'gap-3',
                                        !card.is_active && 'opacity-60',
                                    )}
                                >
                                    <CardHeader>
                                        <CardTitle className="flex items-center justify-between gap-2">
                                            <span>
                                                {card.name}
                                                {card.last_four_digits && (
                                                    <span className="ml-2 font-normal text-muted-foreground">
                                                        ••••{' '}
                                                        {card.last_four_digits}
                                                    </span>
                                                )}
                                            </span>
                                            {!card.is_active && (
                                                <Badge variant="outline">
                                                    Inativo
                                                </Badge>
                                            )}
                                        </CardTitle>
                                        <CardDescription>
                                            Vence dia {card.due_day}
                                            {card.holder &&
                                                ` · titular ${card.holder}`}
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="space-y-3">
                                        <div>
                                            <div className="text-xs text-muted-foreground">
                                                Fatura de{' '}
                                                {formatMonth(filters.month)}
                                            </div>
                                            <div className="text-2xl font-semibold">
                                                <Money
                                                    cents={
                                                        invoice?.total_cents ??
                                                        0
                                                    }
                                                />
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {invoice?.count ?? 0} parcela(s)
                                                · pendente{' '}
                                                <Money
                                                    cents={
                                                        invoice?.pending_cents ??
                                                        0
                                                    }
                                                />
                                            </div>
                                        </div>
                                        <div className="flex gap-1">
                                            <CardFormDialog
                                                card={card}
                                                members={members}
                                                trigger={
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                    >
                                                        <Pencil />
                                                        Editar
                                                    </Button>
                                                }
                                            />
                                            {card.purchases_count === 0 && (
                                                <ConfirmDialog
                                                    url={creditCards.destroy.url(
                                                        card.id,
                                                    )}
                                                    title="Excluir cartão?"
                                                    description={`O cartão “${card.name}” será excluído.`}
                                                    confirmLabel="Excluir"
                                                    trigger={
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            className="text-destructive"
                                                        >
                                                            <Trash2 />
                                                            Excluir
                                                        </Button>
                                                    }
                                                />
                                            )}
                                        </div>
                                    </CardContent>
                                </Card>
                            );
                        })}
                    </section>
                )}

                <section className="space-y-4" aria-labelledby="purchases">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <h2 id="purchases" className="text-lg font-semibold">
                            Compras
                        </h2>
                        <div className="flex flex-wrap items-end gap-3">
                            <nav
                                aria-label="Situação das compras"
                                className="inline-flex rounded-lg bg-muted p-1 text-sm"
                            >
                                {(
                                    [
                                        ['open', 'Em aberto'],
                                        ['finished', 'Quitadas'],
                                    ] as const
                                ).map(([status, label]) => (
                                    <Link
                                        key={status}
                                        href={creditCards.index({
                                            query: {
                                                month: filters.month,
                                                card: filters.card,
                                                status,
                                            },
                                        })}
                                        preserveScroll
                                        aria-current={
                                            filters.status === status
                                                ? 'page'
                                                : undefined
                                        }
                                        className={cn(
                                            'rounded-md px-3 py-1.5 font-medium',
                                            filters.status === status
                                                ? 'bg-background shadow-sm'
                                                : 'text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        {label}
                                    </Link>
                                ))}
                            </nav>
                            <div className="w-48">
                                <FilterSelect
                                    id="filter-card"
                                    label="Cartão"
                                    value={filters.card ?? ''}
                                    onChange={(value) =>
                                        apply({
                                            card: value ? Number(value) : null,
                                        })
                                    }
                                    options={[
                                        ['', 'Todos'],
                                        ...cards.map(
                                            (card) =>
                                                [
                                                    String(card.id),
                                                    card.name,
                                                ] as [string, string],
                                        ),
                                    ]}
                                />
                            </div>
                        </div>
                    </div>

                    {purchases.data.length === 0 ? (
                        <EmptyState
                            icon={ShoppingBag}
                            title={
                                filters.status === 'open'
                                    ? 'Nenhuma compra com parcelas em aberto'
                                    : 'Nenhuma compra quitada'
                            }
                        />
                    ) : (
                        <div className="space-y-3">
                            {purchases.data.map((purchase) => (
                                <PurchaseCard
                                    key={purchase.id}
                                    purchase={purchase}
                                    cards={cards}
                                    categories={categories}
                                    members={members}
                                />
                            ))}
                            <Pagination page={purchases} />
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

function PurchaseCard({
    purchase,
    cards,
    categories,
    members,
}: {
    purchase: Purchase;
    cards: CardRow[];
    categories: Category[];
    members: Member[];
}) {
    const [open, setOpen] = useState(false);
    const isHousehold = purchase.scope === 'household';

    return (
        <Card className="py-4">
            <Collapsible open={open} onOpenChange={setOpen}>
                <CardContent className="space-y-3 px-4">
                    <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                        <div className="space-y-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <h3 className="font-semibold">
                                    {purchase.description}
                                </h3>
                                <Badge variant="outline">
                                    {purchase.credit_card}
                                </Badge>
                                <Badge variant="secondary">
                                    {isHousehold ? 'Casa' : 'Pessoal'}
                                </Badge>
                                {purchase.category && (
                                    <Badge variant="outline">
                                        {purchase.category.name}
                                    </Badge>
                                )}
                            </div>
                            <p className="text-sm text-muted-foreground">
                                {purchase.mode === 'installments' ? (
                                    <>
                                        <Money
                                            cents={purchase.total_amount_cents}
                                        />{' '}
                                        em {purchase.installment_count}x de{' '}
                                        <Money
                                            cents={
                                                purchase.installment_amount_cents
                                            }
                                        />
                                    </>
                                ) : (
                                    <>
                                        <Money
                                            cents={
                                                purchase.installment_amount_cents
                                            }
                                        />{' '}
                                        por mês durante{' '}
                                        {purchase.installment_count} meses
                                        (total{' '}
                                        <Money
                                            cents={purchase.total_amount_cents}
                                        />
                                        )
                                    </>
                                )}{' '}
                                · comprado em{' '}
                                {formatDate(purchase.purchase_date)}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                Faturas de{' '}
                                {formatShortMonth(purchase.first_billing_month)}{' '}
                                a{' '}
                                {formatShortMonth(purchase.last_billing_month)}{' '}
                                · {purchase.paid_count}/
                                {purchase.installment_count} paga(s) · restam{' '}
                                {purchase.remaining_count} (
                                <Money cents={purchase.remaining_cents} />)
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Registrada por {purchase.created_by}
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-1">
                            <PurchaseFormDialog
                                purchase={purchase}
                                cards={cards}
                                categories={categories}
                                month={purchase.first_billing_month}
                                trigger={
                                    <Button size="sm" variant="outline">
                                        <Pencil />
                                        Editar
                                    </Button>
                                }
                            />
                            {purchase.can_change_schedule && (
                                <ConfirmDialog
                                    url={creditCardPurchases.destroy.url(
                                        purchase.id,
                                    )}
                                    title="Excluir compra?"
                                    description={`“${purchase.description}” e suas ${purchase.installment_count} parcelas serão excluídas.`}
                                    confirmLabel="Excluir"
                                    trigger={
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className="text-destructive"
                                        >
                                            <Trash2 />
                                            Excluir
                                        </Button>
                                    }
                                />
                            )}
                        </div>
                    </div>

                    {purchase.notes && (
                        <p className="rounded-md bg-muted px-3 py-2 text-sm whitespace-pre-line">
                            {purchase.notes}
                        </p>
                    )}

                    <CollapsibleTrigger asChild>
                        <Button size="sm" variant="ghost" className="-ml-2">
                            <ChevronDown
                                className={cn(
                                    'transition-transform',
                                    open && 'rotate-180',
                                )}
                            />
                            {open ? 'Ocultar parcelas' : 'Ver parcelas'}
                        </Button>
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Parcela</TableHead>
                                    <TableHead>Fatura</TableHead>
                                    <TableHead>Vencimento</TableHead>
                                    <TableHead className="text-right">
                                        Valor
                                    </TableHead>
                                    {isHousehold &&
                                        members.map((member) => (
                                            <TableHead
                                                key={member.user_id}
                                                className="text-right"
                                            >
                                                {member.first_name}
                                            </TableHead>
                                        ))}
                                    <TableHead>Situação</TableHead>
                                    <TableHead>Pagamento</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {purchase.installments.map((installment) => (
                                    <TableRow key={installment.id}>
                                        <TableCell>
                                            {installment.installment_number}/
                                            {installment.installment_count}
                                        </TableCell>
                                        <TableCell className="capitalize">
                                            {formatShortMonth(
                                                installment.billing_month,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {formatDate(installment.due_date)}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Money
                                                cents={installment.amount_cents}
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
                                                            installment.shares.find(
                                                                (share) =>
                                                                    share.user_id ===
                                                                    member.user_id,
                                                            )?.amount_cents ?? 0
                                                        }
                                                    />
                                                </TableCell>
                                            ))}
                                        <TableCell>
                                            <StatusBadge
                                                status={installment.status}
                                                overdue={installment.is_overdue}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <PaymentActions
                                                occurrence={installment}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CollapsibleContent>
                </CardContent>
            </Collapsible>
        </Card>
    );
}

function CardFormDialog({
    card,
    members,
    trigger,
}: {
    card?: CardRow;
    members: Member[];
    trigger: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const form = card
        ? creditCards.update.form(card.id)
        : creditCards.store.form();

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {card ? 'Editar cartão' : 'Novo cartão'}
                    </DialogTitle>
                    <DialogDescription>
                        Não informe o número completo do cartão: apenas um nome
                        e, se quiser, os 4 últimos dígitos.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...form}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <FormField
                                id="card-name"
                                label="Nome do cartão"
                                error={errors.name}
                            >
                                <Input
                                    id="card-name"
                                    name="name"
                                    required
                                    maxLength={60}
                                    defaultValue={card?.name}
                                    placeholder="Ex.: Nubank Bruno"
                                />
                            </FormField>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <FormField
                                    id="card-digits"
                                    label="4 últimos dígitos"
                                    error={errors.last_four_digits}
                                >
                                    <Input
                                        id="card-digits"
                                        name="last_four_digits"
                                        inputMode="numeric"
                                        pattern="\d{4}"
                                        maxLength={4}
                                        defaultValue={
                                            card?.last_four_digits ?? ''
                                        }
                                    />
                                </FormField>
                                <FormField
                                    id="card-due-day"
                                    label="Dia de vencimento"
                                    error={errors.due_day}
                                >
                                    <Input
                                        id="card-due-day"
                                        name="due_day"
                                        type="number"
                                        min={1}
                                        max={31}
                                        required
                                        defaultValue={card?.due_day ?? 10}
                                    />
                                </FormField>
                            </div>
                            <FormField
                                id="card-holder"
                                label="Titular"
                                error={errors.holder_user_id}
                            >
                                <NativeSelect
                                    id="card-holder"
                                    name="holder_user_id"
                                    defaultValue={card?.holder_user_id ?? ''}
                                >
                                    <option value="">Não informado</option>
                                    {members.map((member) => (
                                        <option
                                            key={member.user_id}
                                            value={member.user_id}
                                        >
                                            {member.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </FormField>
                            <FormField
                                id="card-active"
                                label="Situação"
                                error={errors.is_active}
                            >
                                <NativeSelect
                                    id="card-active"
                                    name="is_active"
                                    defaultValue={
                                        card?.is_active === false ? '0' : '1'
                                    }
                                >
                                    <option value="1">
                                        Ativo (aceita novas compras)
                                    </option>
                                    <option value="0">Inativo</option>
                                </NativeSelect>
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

function PurchaseFormDialog({
    purchase,
    cards,
    categories,
    month,
    trigger,
}: {
    purchase?: Purchase;
    cards: CardRow[];
    categories: Category[];
    month: string;
    trigger: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const [mode, setMode] = useState<Mode>(purchase?.mode ?? 'installments');
    const [amount, setAmount] = useState(
        purchase
            ? purchase.mode === 'installments'
                ? purchase.total_amount_cents
                : purchase.installment_amount_cents
            : 0,
    );
    const [count, setCount] = useState(purchase?.installment_count ?? 1);
    const [firstMonth, setFirstMonth] = useState(
        purchase?.first_billing_month ?? shiftMonth(month, 0),
    );
    const locked = purchase !== undefined && !purchase.can_change_schedule;
    const form = purchase
        ? creditCardPurchases.update.form(purchase.id)
        : creditCardPurchases.store.form();

    const installment = count > 0 ? Math.floor(amount / count) : 0;
    const last = amount - installment * (count - 1);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {purchase ? 'Editar compra' : 'Nova compra no cartão'}
                    </DialogTitle>
                    <DialogDescription>
                        {locked
                            ? 'Esta compra já tem parcelas pagas: só descrição, categoria e observações podem mudar.'
                            : 'Cada parcela vira uma cobrança mensal com pagamento próprio.'}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...form}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            {locked ? (
                                <>
                                    <input
                                        type="hidden"
                                        name="credit_card_id"
                                        value={purchase.credit_card_id}
                                    />
                                    <input
                                        type="hidden"
                                        name="scope"
                                        value={purchase.scope}
                                    />
                                    <input
                                        type="hidden"
                                        name="mode"
                                        value={purchase.mode}
                                    />
                                    <input
                                        type="hidden"
                                        name="amount"
                                        value={centsToDecimal(amount)}
                                    />
                                    <input
                                        type="hidden"
                                        name="installment_count"
                                        value={purchase.installment_count}
                                    />
                                    <input
                                        type="hidden"
                                        name="first_billing_month"
                                        value={purchase.first_billing_month}
                                    />
                                    <input
                                        type="hidden"
                                        name="purchase_date"
                                        value={purchase.purchase_date}
                                    />
                                </>
                            ) : (
                                <>
                                    <ScopeField
                                        defaultValue={
                                            purchase?.scope ?? 'household'
                                        }
                                        error={errors.scope}
                                    />
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <FormField
                                            id="purchase-card"
                                            label="Cartão"
                                            error={errors.credit_card_id}
                                        >
                                            <NativeSelect
                                                id="purchase-card"
                                                name="credit_card_id"
                                                required
                                                defaultValue={
                                                    purchase?.credit_card_id ??
                                                    cards[0]?.id
                                                }
                                            >
                                                {cards.map((card) => (
                                                    <option
                                                        key={card.id}
                                                        value={card.id}
                                                    >
                                                        {card.name}
                                                    </option>
                                                ))}
                                            </NativeSelect>
                                        </FormField>
                                        <FormField
                                            id="purchase-date"
                                            label="Data da compra"
                                            error={errors.purchase_date}
                                        >
                                            <Input
                                                id="purchase-date"
                                                name="purchase_date"
                                                type="date"
                                                required
                                                defaultValue={
                                                    purchase?.purchase_date ??
                                                    today()
                                                }
                                            />
                                        </FormField>
                                    </div>
                                </>
                            )}

                            <FormField
                                id="purchase-description"
                                label="Descrição"
                                error={errors.description}
                            >
                                <Input
                                    id="purchase-description"
                                    name="description"
                                    required
                                    maxLength={120}
                                    defaultValue={purchase?.description}
                                    placeholder="Ex.: Geladeira"
                                />
                            </FormField>

                            {!locked && (
                                <>
                                    <fieldset className="grid gap-1.5">
                                        <legend className="mb-1.5 text-sm font-medium">
                                            Forma de cobrança
                                        </legend>
                                        <div className="grid gap-2 sm:grid-cols-2">
                                            {(
                                                [
                                                    [
                                                        'installments',
                                                        'Parcelado',
                                                        'Valor total dividido nas parcelas',
                                                    ],
                                                    [
                                                        'fixed_monthly',
                                                        'Valor mensal fixo',
                                                        'Mesmo valor cobrado por N meses',
                                                    ],
                                                ] as const
                                            ).map(([value, label, hint]) => (
                                                <label
                                                    key={value}
                                                    className="flex cursor-pointer flex-col rounded-md border p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-muted has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50"
                                                >
                                                    <input
                                                        type="radio"
                                                        name="mode"
                                                        value={value}
                                                        checked={mode === value}
                                                        onChange={() =>
                                                            setMode(value)
                                                        }
                                                        className="sr-only"
                                                    />
                                                    <span className="font-medium">
                                                        {label}
                                                    </span>
                                                    <span className="text-xs text-muted-foreground">
                                                        {hint}
                                                    </span>
                                                </label>
                                            ))}
                                        </div>
                                    </fieldset>

                                    <div className="grid gap-4 sm:grid-cols-3">
                                        <FormField
                                            id="purchase-amount"
                                            label={
                                                mode === 'installments'
                                                    ? 'Valor total'
                                                    : 'Valor mensal'
                                            }
                                            error={errors.amount}
                                        >
                                            <MoneyInput
                                                id="purchase-amount"
                                                name="amount"
                                                required
                                                defaultCents={
                                                    amount > 0 ? amount : null
                                                }
                                                onCentsChange={setAmount}
                                            />
                                        </FormField>
                                        <FormField
                                            id="purchase-count"
                                            label={
                                                mode === 'installments'
                                                    ? 'Parcelas'
                                                    : 'Meses'
                                            }
                                            error={errors.installment_count}
                                        >
                                            <Input
                                                id="purchase-count"
                                                name="installment_count"
                                                type="number"
                                                min={1}
                                                max={120}
                                                required
                                                value={count}
                                                onChange={(event) =>
                                                    setCount(
                                                        Number(
                                                            event.target.value,
                                                        ),
                                                    )
                                                }
                                            />
                                        </FormField>
                                        <FormField
                                            id="purchase-first"
                                            label="Primeira fatura"
                                            error={errors.first_billing_month}
                                        >
                                            <Input
                                                id="purchase-first"
                                                name="first_billing_month"
                                                type="month"
                                                required
                                                value={firstMonth}
                                                onChange={(event) =>
                                                    setFirstMonth(
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </FormField>
                                    </div>

                                    {amount > 0 && count > 0 && (
                                        <p className="rounded-md bg-muted px-3 py-2 text-sm">
                                            {mode === 'installments'
                                                ? installment === last
                                                    ? `${count}x de ${formatMoney(installment)}`
                                                    : `${count - 1}x de ${formatMoney(installment)} + última de ${formatMoney(last)}`
                                                : `${count}x de ${formatMoney(amount)} (total ${formatMoney(amount * count)})`}{' '}
                                            · faturas de{' '}
                                            {formatShortMonth(firstMonth)} a{' '}
                                            {formatShortMonth(
                                                shiftMonth(
                                                    firstMonth,
                                                    count - 1,
                                                ),
                                            )}
                                        </p>
                                    )}
                                </>
                            )}

                            <FormField
                                id="purchase-category"
                                label="Categoria"
                                error={errors.expense_category_id}
                            >
                                <CategorySelect
                                    id="purchase-category"
                                    categories={categories}
                                    defaultValue={purchase?.category?.id}
                                />
                            </FormField>

                            <FormField
                                id="purchase-notes"
                                label="Observações"
                                error={errors.notes}
                                hint="Detalhes da compra, loja, itens etc."
                            >
                                <Textarea
                                    id="purchase-notes"
                                    name="notes"
                                    maxLength={2000}
                                    defaultValue={purchase?.notes ?? ''}
                                />
                            </FormField>

                            <DialogFooter>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {purchase
                                        ? 'Salvar alterações'
                                        : 'Registrar'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

CreditCardsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Cartões e parcelas',
            href: creditCards.index(),
        },
    ],
};
