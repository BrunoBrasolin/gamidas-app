import { Plus, X } from 'lucide-react';
import { LinkCandidates } from '@/components/bank/link-candidates';
import { Money } from '@/components/finance/money';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { centsToDecimal, formatMoney } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { BankCandidate, BankTransaction } from '@/types';

export type SplitPartAction = 'household' | 'link' | 'personal' | 'investment';

/** One part of a transaction whose value is split, while being edited. */
export type SplitPartDraft = {
    key: number;
    action: SplitPartAction;
    /** Null while empty. */
    cents: number | null;
    /** Takes whatever the other parts leave, until typed in. */
    rest: boolean;
    title: string;
    occurrenceId: number | null;
};

/** As many as the server accepts. */
const MAX_PARTS = 10;

const PART_ACTIONS: { value: SplitPartAction; label: string }[] = [
    { value: 'link', label: 'Despesa existente' },
    { value: 'household', label: 'Despesa da casa (nova)' },
    { value: 'personal', label: 'Despesa pessoal' },
    { value: 'investment', label: 'Investimento' },
];

let nextKey = 1;

function newPart(
    transaction: BankTransaction,
    part: Partial<SplitPartDraft> & { action: SplitPartAction },
): SplitPartDraft {
    return {
        key: nextKey++,
        cents: null,
        rest: false,
        title:
            transaction.suggestion?.title ??
            transaction.display_name.slice(0, 120),
        occurrenceId: null,
        ...part,
    };
}

/**
 * Two parts to start with: the expense already chosen with its value (when
 * it is worth less than the transaction) and the rest as a personal
 * expense; without one, an expense to pick and the rest.
 */
export function initialSplit(
    transaction: BankTransaction,
    linked: BankCandidate | null = null,
): SplitPartDraft[] {
    const fits =
        linked !== null &&
        !linked.awaits_amount &&
        linked.amount_cents > 0 &&
        linked.amount_cents < transaction.amount_cents;

    return [
        fits
            ? newPart(transaction, {
                  action: 'link',
                  occurrenceId: linked.id,
                  cents: linked.amount_cents,
              })
            : newPart(transaction, {
                  action:
                      transaction.candidates.length > 0 ? 'link' : 'household',
              }),
        newPart(transaction, { action: 'personal', rest: true }),
    ];
}

/**
 * Each part's value; the part that takes the rest gets what the others
 * leave of the transaction.
 */
export function partCents(parts: SplitPartDraft[], total: number): number[] {
    const typed = parts.reduce(
        (sum, part) => sum + (part.rest ? 0 : (part.cents ?? 0)),
        0,
    );

    return parts.map((part) =>
        part.rest ? Math.max(0, total - typed) : (part.cents ?? 0),
    );
}

/**
 * Ready to submit: at least two parts, each with a value and, when linked,
 * its own expense, adding up to the transaction.
 */
export function splitReady(parts: SplitPartDraft[], total: number): boolean {
    const cents = partCents(parts, total);
    const linked = parts
        .filter((part) => part.action === 'link')
        .map((part) => part.occurrenceId);

    return (
        parts.length >= 2 &&
        cents.every((value) => value > 0) &&
        cents.reduce((sum, value) => sum + value, 0) === total &&
        linked.every((id) => id !== null) &&
        new Set(linked).size === linked.length
    );
}

/**
 * The parts a transaction's value is split into ("Dividir valor"): each one
 * an expense that already exists, a new household expense, a personal
 * expense or an investment, with its value. One part takes the rest of the
 * value until its own is typed.
 */
export function SplitParts({
    transaction,
    parts,
    onChange,
    errors,
}: {
    transaction: BankTransaction;
    parts: SplitPartDraft[];
    onChange: (parts: SplitPartDraft[]) => void;
    errors: Record<string, string | undefined>;
}) {
    const total = transaction.amount_cents;
    const cents = partCents(parts, total);
    const sum = cents.reduce((acc, value) => acc + value, 0);
    const missing = total - sum;

    const update = (key: number, patch: Partial<SplitPartDraft>) =>
        onChange(
            parts.map((part) =>
                part.key === key ? { ...part, ...patch } : part,
            ),
        );

    // A new part goes before the one that takes the rest.
    const add = () => {
        const rest = parts.findIndex((part) => part.rest);
        const part = newPart(transaction, { action: 'personal' });

        onChange(
            rest === -1
                ? [...parts, part]
                : [...parts.slice(0, rest), part, ...parts.slice(rest)],
        );
    };

    return (
        <fieldset className="grid gap-2">
            <legend className="mb-1.5 text-sm font-medium">
                Como dividir <Money cents={total} />?
            </legend>

            {parts.map((part, index) => {
                const id = `split-${transaction.id}-${part.key}`;
                const prefix = `parts[${index}]`;
                const amountError = errors[`parts.${index}.amount`];

                return (
                    <div
                        key={part.key}
                        className="grid gap-3 rounded-md border p-3"
                    >
                        <input
                            type="hidden"
                            name={`${prefix}[action]`}
                            value={part.action}
                        />
                        <input
                            type="hidden"
                            name={`${prefix}[amount]`}
                            value={
                                cents[index] > 0
                                    ? centsToDecimal(cents[index])
                                    : ''
                            }
                        />

                        <div className="flex items-end gap-2">
                            <div className="grid min-w-0 flex-1 gap-1.5">
                                <Label htmlFor={`${id}-action`}>
                                    Parte {index + 1}
                                </Label>
                                <NativeSelect
                                    id={`${id}-action`}
                                    value={part.action}
                                    onChange={(event) =>
                                        update(part.key, {
                                            action: event.target
                                                .value as SplitPartAction,
                                        })
                                    }
                                >
                                    {PART_ACTIONS.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </div>
                            <div className="grid w-32 gap-1.5 sm:w-36">
                                <Label htmlFor={`${id}-amount`}>
                                    {part.rest ? 'Valor (restante)' : 'Valor'}
                                </Label>
                                <Input
                                    id={`${id}-amount`}
                                    type="text"
                                    inputMode="numeric"
                                    autoComplete="off"
                                    placeholder="R$ 0,00"
                                    aria-invalid={
                                        amountError ? true : undefined
                                    }
                                    value={
                                        part.rest || part.cents !== null
                                            ? formatMoney(cents[index])
                                            : ''
                                    }
                                    onChange={(event) => {
                                        const digits = event.target.value
                                            .replace(/\D/g, '')
                                            .replace(/^0+/, '')
                                            .slice(0, 10);

                                        update(part.key, {
                                            rest: false,
                                            cents:
                                                digits === ''
                                                    ? null
                                                    : Number(digits),
                                        });
                                    }}
                                />
                            </div>
                            {parts.length > 2 && (
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    aria-label={`Remover a parte ${index + 1}`}
                                    onClick={() =>
                                        onChange(
                                            parts.filter(
                                                (other) =>
                                                    other.key !== part.key,
                                            ),
                                        )
                                    }
                                >
                                    <X />
                                </Button>
                            )}
                        </div>

                        {missing !== 0 &&
                            !part.rest &&
                            cents[index] + missing > 0 && (
                                <button
                                    type="button"
                                    className="-mt-1 justify-self-end text-xs text-muted-foreground underline underline-offset-4 hover:text-foreground"
                                    onClick={() =>
                                        update(part.key, {
                                            cents: cents[index] + missing,
                                        })
                                    }
                                >
                                    {missing > 0
                                        ? `Somar os ${formatMoney(missing)} que faltam a esta parte`
                                        : `Tirar os ${formatMoney(-missing)} que passam desta parte`}
                                </button>
                            )}

                        {part.action === 'link' ? (
                            <LinkCandidates
                                transaction={transaction}
                                name={`${prefix}[occurrence_id]`}
                                value={part.occurrenceId}
                                exclude={parts.flatMap((other) =>
                                    other.key !== part.key &&
                                    other.action === 'link' &&
                                    other.occurrenceId !== null
                                        ? [other.occurrenceId]
                                        : [],
                                )}
                                amountCents={cents[index]}
                                compact
                                onChange={(candidate) =>
                                    update(part.key, {
                                        occurrenceId: candidate.id,
                                        // An empty part takes the expense's value.
                                        ...(part.cents === null &&
                                        !part.rest &&
                                        !candidate.awaits_amount &&
                                        candidate.amount_cents > 0 &&
                                        candidate.amount_cents < total
                                            ? { cents: candidate.amount_cents }
                                            : {}),
                                    })
                                }
                                error={errors[`parts.${index}.occurrence_id`]}
                            />
                        ) : (
                            <div className="grid gap-1.5">
                                <Label htmlFor={`${id}-title`}>Título</Label>
                                <Input
                                    id={`${id}-title`}
                                    name={`${prefix}[title]`}
                                    maxLength={120}
                                    value={part.title}
                                    onChange={(event) =>
                                        update(part.key, {
                                            title: event.target.value,
                                        })
                                    }
                                />
                                {errors[`parts.${index}.title`] && (
                                    <p className="text-sm text-red-600 dark:text-red-400">
                                        {errors[`parts.${index}.title`]}
                                    </p>
                                )}
                            </div>
                        )}

                        {(amountError || errors[`parts.${index}.action`]) && (
                            <p className="text-sm text-red-600 dark:text-red-400">
                                {amountError ?? errors[`parts.${index}.action`]}
                            </p>
                        )}
                    </div>
                );
            })}

            <div className="flex flex-wrap items-center justify-between gap-2">
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={parts.length >= MAX_PARTS}
                    onClick={add}
                >
                    <Plus />
                    Adicionar parte
                </Button>
                <p
                    className={cn(
                        'text-sm',
                        missing !== 0 && 'text-amber-700 dark:text-amber-400',
                    )}
                >
                    Soma <Money cents={sum} /> de <Money cents={total} />
                    {missing > 0 && ` · faltam ${formatMoney(missing)}`}
                    {missing < 0 && ` · passou ${formatMoney(-missing)}`}
                </p>
            </div>

            <p className="text-xs text-muted-foreground">
                Cada parte é catalogada por conta própria: a despesa existente
                recebe o comprovante do banco (se ainda pendente, passa a valer
                o valor da parte e é marcada como paga quando você pode
                confirmar), as outras viram despesa da casa ou lançamento em
                Finanças pessoais. A divisão não vira regra.
            </p>

            {errors.parts && (
                <p className="text-sm text-red-600 dark:text-red-400">
                    {errors.parts}
                </p>
            )}
        </fieldset>
    );
}
