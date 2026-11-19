import { Form, usePage } from '@inertiajs/react';
import {
    ArrowLeftRight,
    Ban,
    HandCoins,
    Home,
    Link2,
    PiggyBank,
    Split,
    TrendingUp,
    User,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { LinkCandidates, useLinkable } from '@/components/bank/link-candidates';
import { SettlementCandidates } from '@/components/bank/settlement-candidates';
import {
    initialSplit,
    SplitParts,
    splitReady,
} from '@/components/bank/split-parts';
import type { SplitPartDraft } from '@/components/bank/split-parts';
import { FormField } from '@/components/finance/form-field';
import { Money } from '@/components/finance/money';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import bank from '@/routes/bank';
import type {
    BankCandidate,
    BankCatalogAction,
    BankTransaction,
} from '@/types';

type SharedHousehold = {
    household: {
        members: { user_id: number; first_name: string }[];
    } | null;
    auth: { user: { id: number } };
};

const OUT_ACTIONS: {
    value: BankCatalogAction;
    label: string;
    hint: string;
    icon: typeof Home;
}[] = [
    {
        value: 'household',
        label: 'Despesa da casa',
        hint: 'Nova despesa dividida pelos salários',
        icon: Home,
    },
    {
        value: 'link',
        label: 'Despesa existente',
        hint: 'Já cadastrada, pendente ou paga: aluguel, parcela…',
        icon: Link2,
    },
    {
        value: 'personal',
        label: 'Despesa pessoal',
        hint: 'Só sua, em Finanças pessoais',
        icon: User,
    },
    {
        value: 'investment',
        label: 'Investimento',
        hint: 'Aplicação, caixinha, corretora',
        icon: PiggyBank,
    },
    {
        value: 'ignore',
        label: 'Ignorar',
        hint: 'Transferência entre suas contas, estorno…',
        icon: Ban,
    },
];

const OFFSET_ACTION: (typeof OUT_ACTIONS)[number] = {
    value: 'offset',
    label: 'Compensar com outra',
    hint: 'Estorno e cobrança que se anulam',
    icon: ArrowLeftRight,
};

const SETTLEMENT_ACTION: (typeof OUT_ACTIONS)[number] = {
    value: 'settlement',
    label: 'Acerto da casa',
    hint: 'A parte das contas da casa, entre vocês dois',
    icon: HandCoins,
};

const SPLIT_ACTION: (typeof OUT_ACTIONS)[number] = {
    value: 'split',
    label: 'Dividir valor',
    hint: 'Parte numa despesa existente, parte em outra categoria',
    icon: Split,
};

const IN_ACTIONS: typeof OUT_ACTIONS = [
    {
        value: 'income',
        label: 'Receita',
        hint: 'Entra nas suas receitas pessoais',
        icon: TrendingUp,
    },
    {
        value: 'ignore',
        label: 'Ignorar',
        hint: 'Resgate, estorno, transferência entre contas…',
        icon: Ban,
    },
];

/**
 * Catalogues an imported transaction: a new household or personal expense,
 * a link to an expense that already exists, income, an investment, a house
 * settlement between the two members, its value split into parts (part an
 * existing expense, part a personal one), or ignored. The choice can be
 * remembered as a rule for the counterparty, so the next transactions come
 * with it suggested (or applied); a split never is.
 */
export function CatalogDialog({
    transaction,
    trigger,
}: {
    transaction: BankTransaction;
    trigger: ReactNode;
}) {
    const { household, auth } = usePage<SharedHousehold>().props;
    const outgoing = transaction.direction === 'out';
    const canOffset = transaction.offset_candidates.length > 0;
    const others = (household?.members ?? []).filter(
        (member) => member.user_id !== auth.user.id,
    );
    // The house settlement is between the two members of the household.
    const partner = others.length === 1 ? others[0] : null;
    const base = outgoing ? OUT_ACTIONS : IN_ACTIONS;
    const actions = [
        ...base.slice(0, outgoing ? 2 : 1),
        ...(partner ? [SETTLEMENT_ACTION] : []),
        ...base.slice(outgoing ? 2 : 1),
        ...(outgoing ? [SPLIT_ACTION] : []),
        ...(canOffset ? [OFFSET_ACTION] : []),
    ];
    const linkable = useLinkable(transaction);
    const initialAction =
        transaction.suggestion?.action ??
        (outgoing
            ? transaction.candidates.some((candidate) => candidate.strong)
                ? 'link'
                : 'household'
            : partner && transaction.settlement_ids.length > 0
              ? 'settlement'
              : 'income');

    // The suggested expenses, or the month whose shares add up.
    const initialSettlement =
        transaction.suggestion?.action === 'settlement' &&
        transaction.suggestion.occurrence_ids.length > 0
            ? transaction.suggestion.occurrence_ids
            : transaction.settlement_ids;

    // Only a suggested or clearly matching expense comes preselected.
    const initialOccurrence =
        transaction.suggestion?.occurrence_id ??
        transaction.candidates.find((candidate) => candidate.strong)?.id ??
        null;

    // The suggested pair, or the closest pending one.
    const initialOffset =
        transaction.suggestion?.offset_transaction_id ??
        transaction.offset_candidates[0]?.id ??
        null;

    const [open, setOpen] = useState(false);
    const [action, setAction] = useState<BankCatalogAction>(initialAction);
    const [offsetId, setOffsetId] = useState<number | null>(initialOffset);
    const [occurrenceId, setOccurrenceId] = useState<number | null>(
        initialOccurrence,
    );
    const [settlementIds, setSettlementIds] =
        useState<number[]>(initialSettlement);
    const [parts, setParts] = useState<SplitPartDraft[]>(() =>
        initialSplit(transaction),
    );
    const [remember, setRemember] = useState(true);
    const [autoApply, setAutoApply] = useState(false);
    const [amountChoice, setAmountChoice] = useState<boolean | null>(null);

    // Choosing something else than a rule suggested means this counterparty
    // is told apart by value (a PIX of R$ 1.500,00 to someone is the house,
    // other values are the gym): the new rule then defaults to this value
    // only, and the existing one keeps covering the other values.
    const ruleSuggestion = transaction.suggestion?.rule
        ? transaction.suggestion
        : null;
    const adjustsRule =
        ruleSuggestion !== null &&
        (action !== ruleSuggestion.action ||
            (action === 'link' &&
                occurrenceId !== ruleSuggestion.occurrence_id));
    const rememberAmount =
        amountChoice ??
        (adjustsRule || (ruleSuggestion?.rule?.amount_cents ?? null) !== null);

    const otherName = partner?.first_name ?? 'o outro membro';
    const createsRecord = [
        'household',
        'personal',
        'income',
        'investment',
    ].includes(action);
    const splitCreates =
        action === 'split' && parts.some((part) => part.action !== 'link');
    const splitHousehold =
        action === 'split' && parts.some((part) => part.action === 'household');

    // Split starting from the expense chosen to link, when there is one.
    const startSplit = (linked: BankCandidate | null) => {
        setParts(initialSplit(transaction, linked));
        setAction('split');
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (next) {
                    setAction(initialAction);
                    setOccurrenceId(initialOccurrence);
                    setSettlementIds(initialSettlement);
                    setParts(initialSplit(transaction));
                    setOffsetId(initialOffset);
                    setRemember(true);
                    setAutoApply(false);
                    setAmountChoice(null);
                }

                setOpen(next);
            }}
        >
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Catalogar transação</DialogTitle>
                    <DialogDescription>
                        {transaction.display_name} ·{' '}
                        <Money cents={transaction.amount_cents} /> em{' '}
                        {formatDate(transaction.date)}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...bank.transactions.catalog.form(transaction.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <fieldset className="grid gap-1.5">
                                <legend className="mb-1.5 text-sm font-medium">
                                    O que é?
                                </legend>
                                <div className="grid gap-2 sm:grid-cols-2">
                                    {actions.map((option) => (
                                        <label
                                            key={option.value}
                                            className={cn(
                                                'flex cursor-pointer items-start gap-2 rounded-md border p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-muted',
                                                'has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50',
                                            )}
                                        >
                                            <input
                                                type="radio"
                                                name="action"
                                                value={option.value}
                                                checked={
                                                    action === option.value
                                                }
                                                onChange={() =>
                                                    option.value === 'split' &&
                                                    action === 'link' &&
                                                    occurrenceId !== null
                                                        ? startSplit(
                                                              linkable.find(
                                                                  (candidate) =>
                                                                      candidate.id ===
                                                                      occurrenceId,
                                                              ) ?? null,
                                                          )
                                                        : setAction(
                                                              option.value,
                                                          )
                                                }
                                                className="sr-only"
                                            />
                                            <option.icon
                                                className="mt-0.5 size-4 shrink-0"
                                                aria-hidden
                                            />
                                            <span>
                                                <span className="block font-medium">
                                                    {option.label}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {option.hint}
                                                </span>
                                            </span>
                                        </label>
                                    ))}
                                </div>
                                {errors.action && (
                                    <p className="text-sm text-red-600 dark:text-red-400">
                                        {errors.action}
                                    </p>
                                )}
                            </fieldset>

                            {action === 'split' && (
                                <SplitParts
                                    transaction={transaction}
                                    parts={parts}
                                    onChange={setParts}
                                    errors={errors}
                                />
                            )}

                            {(createsRecord || splitCreates) && (
                                <>
                                    {createsRecord && (
                                        <FormField
                                            id={`catalog-title-${transaction.id}`}
                                            label="Título"
                                            error={errors.title}
                                        >
                                            <Input
                                                id={`catalog-title-${transaction.id}`}
                                                name="title"
                                                maxLength={120}
                                                defaultValue={
                                                    transaction.suggestion
                                                        ?.title ??
                                                    transaction.display_name.slice(
                                                        0,
                                                        120,
                                                    )
                                                }
                                            />
                                        </FormField>
                                    )}
                                    <FormField
                                        id={`catalog-date-${transaction.id}`}
                                        label={
                                            action === 'household'
                                                ? 'Vencimento'
                                                : splitHousehold
                                                  ? 'Data (e vencimento da despesa da casa)'
                                                  : 'Data'
                                        }
                                        error={errors.date}
                                        hint={
                                            transaction.account.type ===
                                                'CREDIT' &&
                                            transaction.reference_date !==
                                                transaction.date
                                                ? `Compra em ${formatDate(transaction.date)}; a fatura vence em ${formatDate(transaction.reference_date)}.`
                                                : undefined
                                        }
                                    >
                                        <Input
                                            id={`catalog-date-${transaction.id}`}
                                            name="date"
                                            type="date"
                                            required
                                            defaultValue={
                                                transaction.reference_date
                                            }
                                        />
                                    </FormField>
                                    {(action === 'household' ||
                                        splitHousehold) && (
                                        <p className="rounded-md border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
                                            A despesa entra em Despesas da casa
                                            como pendente, com o comprovante do
                                            seu banco. Como foi você quem
                                            cadastrou, {otherName} confirma o
                                            pagamento.
                                        </p>
                                    )}
                                    <FormField
                                        id={`catalog-notes-${transaction.id}`}
                                        label="Observações"
                                        error={errors.notes}
                                    >
                                        <Textarea
                                            id={`catalog-notes-${transaction.id}`}
                                            name="notes"
                                            maxLength={2000}
                                            rows={2}
                                        />
                                    </FormField>
                                </>
                            )}

                            {action === 'offset' && (
                                <OffsetCandidates
                                    transaction={transaction}
                                    value={offsetId}
                                    onChange={setOffsetId}
                                    error={errors.offset_transaction_id}
                                />
                            )}

                            {action === 'settlement' && partner && (
                                <SettlementCandidates
                                    transaction={transaction}
                                    meId={auth.user.id}
                                    partner={partner}
                                    value={settlementIds}
                                    onChange={setSettlementIds}
                                    error={errors.occurrence_ids}
                                />
                            )}

                            {action === 'link' && (
                                <LinkCandidates
                                    transaction={transaction}
                                    value={occurrenceId}
                                    onChange={(candidate) =>
                                        setOccurrenceId(candidate.id)
                                    }
                                    onSplit={startSplit}
                                    error={errors.occurrence_id}
                                />
                            )}

                            <div
                                className={cn(
                                    'space-y-2 rounded-md border p-3',
                                    (action === 'offset' ||
                                        action === 'split') &&
                                        'hidden',
                                )}
                            >
                                <input
                                    type="hidden"
                                    name="remember"
                                    value={
                                        remember && action !== 'split'
                                            ? '1'
                                            : '0'
                                    }
                                />
                                <input
                                    type="hidden"
                                    name="auto_apply"
                                    value={autoApply ? '1' : '0'}
                                />
                                <input
                                    type="hidden"
                                    name="remember_amount"
                                    value={
                                        remember && rememberAmount ? '1' : '0'
                                    }
                                />
                                <div className="flex items-start gap-2">
                                    <Checkbox
                                        id={`catalog-remember-${transaction.id}`}
                                        checked={remember}
                                        onCheckedChange={(checked) =>
                                            setRemember(checked === true)
                                        }
                                    />
                                    <Label
                                        htmlFor={`catalog-remember-${transaction.id}`}
                                        className="leading-snug font-normal"
                                    >
                                        {`Lembrar para “${transaction.display_name}”: as próximas transações vêm com esta sugestão`}
                                        {action === 'link' &&
                                            ' (só para despesas recorrentes)'}
                                    </Label>
                                </div>
                                {remember && (
                                    <div
                                        role="radiogroup"
                                        aria-label="Valor da regra"
                                        className="space-y-1 pl-6 text-sm"
                                    >
                                        {(
                                            [
                                                [false, 'Para qualquer valor'],
                                                [
                                                    true,
                                                    `Só quando o valor for ${formatMoney(transaction.amount_cents)}`,
                                                ],
                                            ] as const
                                        ).map(([onlyThis, label]) => (
                                            <label
                                                key={label}
                                                className="flex cursor-pointer items-center gap-2"
                                            >
                                                <input
                                                    type="radio"
                                                    checked={
                                                        rememberAmount ===
                                                        onlyThis
                                                    }
                                                    onChange={() =>
                                                        setAmountChoice(
                                                            onlyThis,
                                                        )
                                                    }
                                                    className="accent-primary"
                                                />
                                                {label}
                                            </label>
                                        ))}
                                        {adjustsRule &&
                                            rememberAmount &&
                                            ruleSuggestion?.rule && (
                                                <p className="text-xs text-muted-foreground">
                                                    A regra “
                                                    {ruleSuggestion.rule.label}”
                                                    continua sugerindo “
                                                    {ruleSuggestion.label}” para
                                                    os outros valores.
                                                </p>
                                            )}
                                    </div>
                                )}
                                {remember && (
                                    <div className="flex items-start gap-2 pl-6">
                                        <Checkbox
                                            id={`catalog-auto-${transaction.id}`}
                                            checked={autoApply}
                                            onCheckedChange={(checked) =>
                                                setAutoApply(checked === true)
                                            }
                                        />
                                        <div className="grid gap-0.5">
                                            <Label
                                                htmlFor={`catalog-auto-${transaction.id}`}
                                                className="leading-snug font-normal"
                                            >
                                                Catalogar sozinho, sem passar
                                                pela caixa de entrada
                                            </Label>
                                            <p className="text-xs text-muted-foreground">
                                                As pendentes que batem com a
                                                regra também são catalogadas
                                                agora.
                                            </p>
                                        </div>
                                    </div>
                                )}
                            </div>

                            <DialogFooter>
                                <Button
                                    type="submit"
                                    disabled={
                                        processing ||
                                        (action === 'link' &&
                                            occurrenceId === null) ||
                                        (action === 'offset' &&
                                            offsetId === null) ||
                                        (action === 'settlement' &&
                                            settlementIds.length === 0) ||
                                        (action === 'split' &&
                                            !splitReady(
                                                parts,
                                                transaction.amount_cents,
                                            ))
                                    }
                                >
                                    {processing && <Spinner />}
                                    {action === 'ignore'
                                        ? 'Ignorar'
                                        : action === 'offset'
                                          ? 'Compensar'
                                          : action === 'link'
                                            ? 'Vincular'
                                            : action === 'settlement'
                                              ? 'Registrar acerto'
                                              : action === 'split'
                                                ? 'Dividir'
                                                : 'Catalogar'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * The pending transactions of the same account, value and opposite
 * direction that cancel this one out (a refund and its charge). Both end up
 * ignored, pointing at each other.
 */
function OffsetCandidates({
    transaction,
    value,
    onChange,
    error,
}: {
    transaction: BankTransaction;
    value: number | null;
    onChange: (id: number) => void;
    error?: string;
}) {
    return (
        <fieldset className="grid gap-1.5">
            <legend className="mb-1.5 text-sm font-medium">
                Com qual transação?
            </legend>
            <div className="grid gap-2">
                {transaction.offset_candidates.map((other) => (
                    <label
                        key={other.id}
                        className={cn(
                            'flex cursor-pointer items-start justify-between gap-3 rounded-md border p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-muted',
                            'has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50',
                        )}
                    >
                        <input
                            type="radio"
                            name="offset_transaction_id"
                            value={other.id}
                            checked={value === other.id}
                            onChange={() => onChange(other.id)}
                            className="sr-only"
                        />
                        <span className="min-w-0">
                            <span className="block font-medium break-words">
                                {other.display_name}
                            </span>
                            {other.display_name !== other.description && (
                                <span className="block truncate text-xs text-muted-foreground">
                                    {other.description}
                                </span>
                            )}
                        </span>
                        <span className="shrink-0 text-right">
                            <Money
                                cents={
                                    other.direction === 'out'
                                        ? -transaction.amount_cents
                                        : transaction.amount_cents
                                }
                                signed
                            />
                            <span className="block text-xs text-muted-foreground">
                                {formatDate(other.date)}
                            </span>
                        </span>
                    </label>
                ))}
            </div>
            <p className="text-xs text-muted-foreground">
                As duas ficam ignoradas, uma apontando para a outra: nada vira
                despesa e o saldo fica zerado. “Desfazer” em qualquer uma
                devolve as duas para a caixa de entrada.
            </p>
            {error && (
                <p className="text-sm text-red-600 dark:text-red-400">
                    {error}
                </p>
            )}
        </fieldset>
    );
}
