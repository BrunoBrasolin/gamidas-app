import { usePage } from '@inertiajs/react';
import { Split } from 'lucide-react';
import { useState } from 'react';
import { Money } from '@/components/finance/money';
import { StatusBadge } from '@/components/finance/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate, formatMoney, formatMonth } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { BankCandidate, BankTransaction } from '@/types';

/**
 * Lowercase without accents, to search titles as typed.
 */
function normalize(text: string): string {
    return text
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();
}

/**
 * The expenses the member may link: the suggestions of the transaction and
 * every other one listed for the inbox page.
 */
export function useLinkable(transaction: BankTransaction): BankCandidate[] {
    const { linkable = [] } = usePage<{ linkable?: BankCandidate[] }>().props;
    const suggested = new Set(transaction.candidates.map((c) => c.id));

    return [
        ...transaction.candidates,
        ...linkable.filter((candidate) => !suggested.has(candidate.id)),
    ];
}

/**
 * The expense to link to: the suggestions first, then any other expense the
 * member can see (household ones and their own) not linked yet — pending, or
 * already paid by hand around the transaction's date — nearest to it,
 * searchable by title, month or "paga".
 *
 * Also picks the expense of one part of a split transaction (`compact`):
 * the chosen one alone, with a button to choose another, and the radio
 * named after the part.
 */
export function LinkCandidates({
    transaction,
    value,
    onChange,
    error,
    name = 'occurrence_id',
    exclude = [],
    amountCents = transaction.amount_cents,
    onSplit,
    compact = false,
}: {
    transaction: BankTransaction;
    value: number | null;
    onChange: (candidate: BankCandidate) => void;
    error?: string;
    /** The radio's name: one per part when the value is split. */
    name?: string;
    /** Expenses already chosen in the other parts of a split. */
    exclude?: number[];
    /** What the expense comes to be worth: the transaction or the part. */
    amountCents?: number;
    /** Offered when the chosen expense is worth less than the transaction. */
    onSplit?: (candidate: BankCandidate) => void;
    compact?: boolean;
}) {
    const { linkable = [] } = usePage<{ linkable?: BankCandidate[] }>().props;
    const [search, setSearch] = useState('');
    const [browsing, setBrowsing] = useState(false);

    const excluded = new Set(exclude.filter((id) => id !== value));
    const suggestions = transaction.candidates.filter(
        (candidate) => !excluded.has(candidate.id),
    );
    const suggested = new Set(transaction.candidates.map((c) => c.id));
    const reference = Date.parse(transaction.reference_date);
    const term = normalize(search.trim());
    const pool = linkable.filter(
        (candidate) =>
            !suggested.has(candidate.id) && !excluded.has(candidate.id),
    );
    const others = pool
        .filter(
            (candidate) =>
                term === '' ||
                normalize(
                    `${candidate.title} ${formatMonth(candidate.billing_month)} ${candidate.paid ? 'paga' : 'pendente'}`,
                ).includes(term),
        )
        .sort(
            (a, b) =>
                Math.abs(Date.parse(a.due_date) - reference) -
                Math.abs(Date.parse(b.due_date) - reference),
        )
        .slice(0, term === '' ? 8 : 30);

    // Keep the chosen expense in view while searching for another.
    const chosen = pool.find(
        (candidate) =>
            candidate.id === value &&
            !others.some((other) => other.id === value),
    );
    const selected =
        suggestions.find((candidate) => candidate.id === value) ??
        pool.find((candidate) => candidate.id === value);

    if (suggestions.length === 0 && pool.length === 0) {
        return (
            <p className="rounded-md border border-dashed px-3 py-4 text-center text-sm text-muted-foreground">
                Nenhuma despesa para vincular. Cadastre como despesa da casa ou
                pessoal.
            </p>
        );
    }

    // Within a part, the chosen expense alone until another is wanted.
    if (compact && selected && !browsing) {
        return (
            <div className="grid gap-1.5">
                <CandidateOption
                    candidate={selected}
                    name={name}
                    checked
                    onChange={onChange}
                />
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-xs text-muted-foreground">
                        {selected.paid
                            ? 'Já paga: o vínculo só anexa o comprovante do banco.'
                            : selected.amount_cents !== amountCents &&
                                amountCents > 0
                              ? `Passa a valer ${formatMoney(amountCents)}, o valor desta parte.`
                              : 'Se você puder confirmar o pagamento, ela é marcada como paga.'}
                    </p>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => setBrowsing(true)}
                    >
                        Trocar despesa
                    </Button>
                </div>
                {error && (
                    <p className="text-sm text-red-600 dark:text-red-400">
                        {error}
                    </p>
                )}
            </div>
        );
    }

    const pick = (candidate: BankCandidate) => {
        setBrowsing(false);
        onChange(candidate);
    };

    const splittable =
        onSplit &&
        selected &&
        !selected.awaits_amount &&
        selected.amount_cents > 0 &&
        selected.amount_cents < amountCents
            ? selected
            : null;

    return (
        <fieldset className="grid gap-1.5">
            <legend
                className={cn(
                    'mb-1.5 text-sm font-medium',
                    compact && 'sr-only',
                )}
            >
                Qual despesa?
            </legend>

            {suggestions.length > 0 && (
                <div className="grid gap-2">
                    <p className="text-xs font-medium text-muted-foreground">
                        Sugestões
                    </p>
                    {suggestions.map((candidate) => (
                        <CandidateOption
                            key={candidate.id}
                            candidate={candidate}
                            name={name}
                            checked={value === candidate.id}
                            onChange={pick}
                        />
                    ))}
                </div>
            )}

            {pool.length > 0 && (
                <div className="mt-2 grid gap-2">
                    <Label
                        htmlFor={`link-search-${transaction.id}-${name}`}
                        className="text-xs font-medium text-muted-foreground"
                    >
                        {suggestions.length > 0
                            ? 'Outra despesa'
                            : 'Despesas cadastradas'}
                    </Label>
                    <Input
                        id={`link-search-${transaction.id}-${name}`}
                        type="search"
                        placeholder="Buscar por nome, mês ou “paga” (ex.: aluguel, dezembro)"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        autoComplete="off"
                    />
                    {chosen && (
                        <CandidateOption
                            candidate={chosen}
                            name={name}
                            checked
                            onChange={pick}
                        />
                    )}
                    {others.map((candidate) => (
                        <CandidateOption
                            key={candidate.id}
                            candidate={candidate}
                            name={name}
                            checked={value === candidate.id}
                            onChange={pick}
                        />
                    ))}
                    {others.length === 0 && (
                        <p className="text-xs text-muted-foreground">
                            Nenhuma despesa com esse nome.
                        </p>
                    )}
                </div>
            )}

            {!compact &&
                (selected?.paid ? (
                    <p className="rounded-md border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
                        Esta despesa já foi paga
                        {selected.paid_at
                            ? ` em ${formatDate(selected.paid_at)}`
                            : ''}
                        : o vínculo só anexa o comprovante do banco. Valor e
                        pagamento continuam como estão
                        {selected.amount_cents !== amountCents &&
                            ` — ela tem ${formatMoney(selected.amount_cents)} e o banco mostra ${formatMoney(amountCents)}`}
                        .
                    </p>
                ) : selected &&
                  !selected.awaits_amount &&
                  selected.amount_cents !== amountCents ? (
                    <p className="text-xs text-muted-foreground">
                        Ela tem {formatMoney(selected.amount_cents)}: ao
                        vincular, passa a valer {formatMoney(amountCents)}, o
                        valor do banco, e se você puder confirmar o pagamento é
                        marcada como paga.
                    </p>
                ) : (
                    <p className="text-xs text-muted-foreground">
                        O valor da despesa passa a ser o do banco e, se você
                        puder confirmar o pagamento, ela é marcada como paga.
                    </p>
                ))}

            {splittable && onSplit && (
                <div className="flex flex-col gap-2 rounded-md border border-dashed p-3 text-xs sm:flex-row sm:items-center sm:justify-between">
                    <span>
                        Só uma parte da transação é desta despesa? Divida:{' '}
                        <Money cents={splittable.amount_cents} /> nela e{' '}
                        <Money cents={amountCents - splittable.amount_cents} />{' '}
                        em outra categoria.
                    </span>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="shrink-0"
                        onClick={() => onSplit(splittable)}
                    >
                        <Split />
                        Dividir valor
                    </Button>
                </div>
            )}

            {error && (
                <p className="text-sm text-red-600 dark:text-red-400">
                    {error}
                </p>
            )}
        </fieldset>
    );
}

function CandidateOption({
    candidate,
    name,
    checked,
    onChange,
}: {
    candidate: BankCandidate;
    name: string;
    checked: boolean;
    onChange: (candidate: BankCandidate) => void;
}) {
    return (
        <label
            className={cn(
                'flex cursor-pointer items-start justify-between gap-3 rounded-md border p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-muted',
                'has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50',
            )}
        >
            <input
                type="radio"
                name={name}
                value={candidate.id}
                checked={checked}
                onChange={() => onChange(candidate)}
                className="sr-only"
            />
            <span className="min-w-0">
                <span className="block font-medium">{candidate.title}</span>
                <span className="mt-0.5 flex flex-wrap gap-1">
                    <Badge variant="outline">
                        {formatMonth(candidate.billing_month)}
                    </Badge>
                    {candidate.installment_number && (
                        <Badge variant="outline">
                            Parcela {candidate.installment_number}/
                            {candidate.installment_count}
                        </Badge>
                    )}
                    {candidate.is_recurring && (
                        <Badge variant="secondary">Recorrente</Badge>
                    )}
                    {candidate.paid && <StatusBadge status="paid" />}
                </span>
            </span>
            <span className="text-right">
                {candidate.awaits_amount ? (
                    <span className="text-xs text-amber-700 dark:text-amber-400">
                        Valor a definir
                    </span>
                ) : (
                    <Money cents={candidate.amount_cents} />
                )}
                <span className="block text-xs text-muted-foreground">
                    vence {formatDate(candidate.due_date)}
                </span>
            </span>
        </label>
    );
}
