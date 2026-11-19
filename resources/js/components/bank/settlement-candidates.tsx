import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Money } from '@/components/finance/money';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { formatDate, formatMoney, formatMonth } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { BankSettlementCandidate, BankTransaction } from '@/types';

/** Months shown before "Mostrar outros meses". */
const NEAREST_MONTHS = 3;

/**
 * Index of a "YYYY-MM" month, to measure how far apart two months are.
 */
function monthIndex(month: string): number {
    const [year, number] = month.split('-').map(Number);

    return year * 12 + number;
}

/**
 * The household expenses a transfer between the two members pays (the
 * house settlement): what the sender owed on the expenses the other one
 * registered and, for a net transfer, minus what they were owed back. The
 * month whose shares add up to the transfer comes preselected.
 */
export function SettlementCandidates({
    transaction,
    meId,
    partner,
    value,
    onChange,
    error,
}: {
    transaction: BankTransaction;
    meId: number;
    partner: { user_id: number; first_name: string };
    value: number[];
    onChange: (ids: number[]) => void;
    error?: string;
}) {
    const { settleable = [] } = usePage<{
        settleable?: BankSettlementCandidate[];
    }>().props;
    const [showAll, setShowAll] = useState(false);

    const senderId = transaction.direction === 'in' ? partner.user_id : meId;
    const signed = (candidate: BankSettlementCandidate) =>
        candidate.payer_user_id === senderId
            ? candidate.share_cents
            : -candidate.share_cents;
    const whose = (candidate: BankSettlementCandidate) =>
        candidate.payer_user_id === meId
            ? 'sua parte'
            : `parte de ${partner.first_name}`;

    const reference = monthIndex(transaction.date.slice(0, 7));
    const distance = (month: string) =>
        Math.abs(monthIndex(month) - reference) * 2 +
        (monthIndex(month) > reference ? 1 : 0);

    const groups = new Map<string, BankSettlementCandidate[]>();

    for (const candidate of settleable) {
        groups.set(candidate.billing_month, [
            ...(groups.get(candidate.billing_month) ?? []),
            candidate,
        ]);
    }

    const months = [...groups.keys()].sort((a, b) => distance(a) - distance(b));
    const chosen = new Set(value);
    // Months with something chosen always stay in view.
    const visible = showAll
        ? months
        : months.filter(
              (month, index) =>
                  index < NEAREST_MONTHS ||
                  groups.get(month)?.some((c) => chosen.has(c.id)),
          );

    const total = settleable
        .filter((candidate) => chosen.has(candidate.id))
        .reduce((sum, candidate) => sum + signed(candidate), 0);
    const difference = transaction.amount_cents - total;

    function toggle(ids: number[], checked: boolean) {
        const next = new Set(chosen);

        for (const id of ids) {
            if (checked) {
                next.add(id);
            } else {
                next.delete(id);
            }
        }

        onChange(settleable.filter((c) => next.has(c.id)).map((c) => c.id));
    }

    if (settleable.length === 0) {
        return (
            <p className="rounded-md border border-dashed px-3 py-4 text-center text-sm text-muted-foreground">
                Nenhuma despesa da casa pendente para acertar.
            </p>
        );
    }

    return (
        <fieldset className="grid gap-1.5">
            <legend className="mb-1.5 text-sm font-medium">
                Quais despesas este acerto paga?
            </legend>

            {value.map((id) => (
                <input
                    key={id}
                    type="hidden"
                    name="occurrence_ids[]"
                    value={id}
                />
            ))}

            <div className="grid gap-3">
                {visible.map((month) => {
                    const items = groups.get(month) ?? [];
                    const picked = items.filter((c) => chosen.has(c.id));
                    const monthTotal = items.reduce(
                        (sum, candidate) => sum + signed(candidate),
                        0,
                    );
                    const id = `settle-month-${transaction.id}-${month}`;

                    return (
                        <div key={month} className="rounded-md border">
                            <div className="flex items-center justify-between gap-3 border-b bg-muted/40 px-3 py-2">
                                <label
                                    htmlFor={id}
                                    className="flex cursor-pointer items-center gap-2 text-sm font-medium"
                                >
                                    <Checkbox
                                        id={id}
                                        checked={
                                            picked.length === 0
                                                ? false
                                                : picked.length === items.length
                                                  ? true
                                                  : 'indeterminate'
                                        }
                                        onCheckedChange={(checked) =>
                                            toggle(
                                                items.map((c) => c.id),
                                                checked === true,
                                            )
                                        }
                                    />
                                    <span className="first-letter:uppercase">
                                        {formatMonth(month)}
                                    </span>
                                </label>
                                <Money
                                    cents={monthTotal}
                                    className="text-sm text-muted-foreground"
                                />
                            </div>
                            <ul className="divide-y">
                                {items.map((candidate) => (
                                    <li key={candidate.id}>
                                        <label className="flex cursor-pointer items-start justify-between gap-3 px-3 py-2 text-sm">
                                            <span className="flex min-w-0 items-start gap-2">
                                                <Checkbox
                                                    checked={chosen.has(
                                                        candidate.id,
                                                    )}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        toggle(
                                                            [candidate.id],
                                                            checked === true,
                                                        )
                                                    }
                                                    className="mt-0.5"
                                                />
                                                <span className="min-w-0">
                                                    <span className="block font-medium break-words">
                                                        {candidate.title}
                                                    </span>
                                                    <span className="mt-0.5 flex flex-wrap gap-1 text-xs text-muted-foreground">
                                                        <span>
                                                            <Money
                                                                cents={
                                                                    candidate.amount_cents
                                                                }
                                                            />{' '}
                                                            · vence{' '}
                                                            {formatDate(
                                                                candidate.due_date,
                                                            )}
                                                        </span>
                                                        {candidate.installment_number && (
                                                            <Badge variant="outline">
                                                                Parcela{' '}
                                                                {
                                                                    candidate.installment_number
                                                                }
                                                                /
                                                                {
                                                                    candidate.installment_count
                                                                }
                                                            </Badge>
                                                        )}
                                                        {candidate.settled_by_partner && (
                                                            <Badge variant="secondary">
                                                                Já acertada pelo
                                                                banco de{' '}
                                                                {
                                                                    partner.first_name
                                                                }
                                                            </Badge>
                                                        )}
                                                    </span>
                                                </span>
                                            </span>
                                            <span className="shrink-0 text-right">
                                                <Money
                                                    cents={signed(candidate)}
                                                    signed
                                                />
                                                <span className="block text-xs text-muted-foreground">
                                                    {whose(candidate)}
                                                </span>
                                            </span>
                                        </label>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    );
                })}
            </div>

            {visible.length < months.length && (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="justify-self-start"
                    onClick={() => setShowAll(true)}
                >
                    Mostrar outros meses ({months.length - visible.length})
                </Button>
            )}

            <div
                className={cn(
                    'mt-1 flex flex-wrap items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm',
                    value.length > 0 && difference === 0
                        ? 'border-emerald-500/60 bg-emerald-500/5'
                        : value.length > 0 &&
                              'border-amber-500/60 bg-amber-500/5',
                )}
            >
                <span>
                    Selecionado <Money cents={total} /> de{' '}
                    <Money cents={transaction.amount_cents} />
                </span>
                <span
                    className={cn(
                        'text-xs',
                        difference === 0
                            ? 'text-emerald-700 dark:text-emerald-400'
                            : 'text-amber-700 dark:text-amber-400',
                    )}
                >
                    {value.length === 0
                        ? 'Escolha as despesas'
                        : difference === 0
                          ? 'Bate com o valor'
                          : difference > 0
                            ? `Faltam ${formatMoney(difference)}`
                            : `Passa ${formatMoney(-difference)}`}
                </span>
            </div>

            <p className="text-xs text-muted-foreground">
                As despesas marcadas ficam pagas em{' '}
                {formatDate(transaction.date)}, em nome de quem devia a parte,
                com esta transação como comprovante. Nada entra nas suas
                receitas. “Desfazer” reabre todas.
            </p>
            {error && (
                <p className="text-sm text-red-600 dark:text-red-400">
                    {error}
                </p>
            )}
        </fieldset>
    );
}
