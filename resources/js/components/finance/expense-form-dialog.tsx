import { Form } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { FormField } from '@/components/finance/form-field';
import { MoneyInput } from '@/components/finance/money-input';
import { ScopeField } from '@/components/finance/scope-field';
import { Button } from '@/components/ui/button';
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
import { Textarea } from '@/components/ui/textarea';
import { formatMoney } from '@/lib/format';
import expenses from '@/routes/expenses';
import type { Occurrence, Scope } from '@/types';

const MAX_INSTALLMENTS = 120;

/**
 * Creates a one-off expense, optionally split into monthly installments of
 * the same amount, or edits a single monthly occurrence of any expense. The
 * billing month is always the month of the due date.
 */
export function ExpenseFormDialog({
    trigger,
    month,
    scope = 'household',
    occurrence,
}: {
    trigger: ReactNode;
    month: string;
    scope?: Scope;
    occurrence?: Occurrence;
}) {
    const [open, setOpen] = useState(false);
    const editing = occurrence !== undefined;
    const isRecurring = occurrence?.source === 'recurring';
    const awaitsAmount = occurrence?.awaits_amount ?? false;
    const [amountCents, setAmountCents] = useState(
        occurrence?.amount_cents ?? 0,
    );
    const [installmentsInput, setInstallmentsInput] = useState('1');
    const parsedInstallments = Number.parseInt(installmentsInput, 10);
    const installments =
        parsedInstallments >= 1 && parsedInstallments <= MAX_INSTALLMENTS
            ? parsedInstallments
            : 1;
    const form = editing
        ? expenses.update.form(occurrence.id)
        : expenses.store.form();

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (next) {
                    // The fields remount empty, so the total starts over too.
                    setAmountCents(occurrence?.amount_cents ?? 0);
                    setInstallmentsInput('1');
                }

                setOpen(next);
            }}
        >
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {awaitsAmount
                            ? `Valor de “${occurrence?.title}”`
                            : editing
                              ? 'Editar despesa'
                              : 'Nova despesa avulsa'}
                    </DialogTitle>
                    <DialogDescription>
                        {awaitsAmount
                            ? 'Esta conta tem valor variável: informe o valor deste mês. Os outros meses não mudam.'
                            : isRecurring
                              ? 'A alteração vale só para este mês. Para mudar os próximos meses, edite a despesa recorrente.'
                              : occurrence?.installment_number
                                ? `Parcela ${occurrence.installment_number}/${occurrence.installment_count}: a alteração vale só para esta parcela.`
                                : 'Para contas que se repetem todo mês, use Despesas recorrentes. O mês de referência é o mês do vencimento.'}
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
                            {!editing && (
                                <ScopeField
                                    defaultValue={scope}
                                    error={errors.scope}
                                />
                            )}

                            <FormField
                                id="expense-title"
                                label="Título"
                                error={errors.title}
                            >
                                <Input
                                    id="expense-title"
                                    name="title"
                                    required
                                    maxLength={120}
                                    defaultValue={occurrence?.title}
                                    aria-invalid={!!errors.title}
                                />
                            </FormField>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <FormField
                                    id="expense-amount"
                                    label={
                                        editing ? 'Valor' : 'Valor da parcela'
                                    }
                                    error={errors.amount}
                                >
                                    <MoneyInput
                                        id="expense-amount"
                                        name="amount"
                                        required
                                        defaultCents={
                                            awaitsAmount
                                                ? null
                                                : occurrence?.amount_cents
                                        }
                                        autoFocus={awaitsAmount}
                                        onCentsChange={setAmountCents}
                                        aria-invalid={!!errors.amount}
                                    />
                                </FormField>

                                {editing ? (
                                    <DueDateField
                                        defaultValue={occurrence.due_date}
                                        error={errors.due_date}
                                        hint={
                                            isRecurring
                                                ? 'Deve ficar no mês desta recorrência.'
                                                : undefined
                                        }
                                    />
                                ) : (
                                    <FormField
                                        id="expense-installments"
                                        label="Parcelas"
                                        error={errors.installments}
                                    >
                                        <Input
                                            id="expense-installments"
                                            name="installments"
                                            type="number"
                                            inputMode="numeric"
                                            min={1}
                                            max={MAX_INSTALLMENTS}
                                            required
                                            value={installmentsInput}
                                            onChange={(event) =>
                                                setInstallmentsInput(
                                                    event.target.value,
                                                )
                                            }
                                            aria-invalid={!!errors.installments}
                                        />
                                    </FormField>
                                )}
                            </div>

                            {!editing && (
                                <>
                                    <DueDateField
                                        defaultValue={`${month}-10`}
                                        error={errors.due_date}
                                        hint={
                                            installments > 1
                                                ? 'Vencimento da 1ª parcela; as demais vencem no mesmo dia dos meses seguintes.'
                                                : undefined
                                        }
                                    />

                                    <div className="flex items-center justify-between rounded-md border bg-muted/40 px-3 py-2 text-sm">
                                        <span className="text-muted-foreground">
                                            Valor total
                                            {installments > 1 &&
                                                ` (${installments}× ${formatMoney(amountCents)})`}
                                        </span>
                                        <span className="font-medium tabular-nums">
                                            {formatMoney(
                                                amountCents * installments,
                                            )}
                                        </span>
                                    </div>
                                </>
                            )}

                            <FormField
                                id="expense-notes"
                                label="Observações"
                                error={errors.notes}
                            >
                                <Textarea
                                    id="expense-notes"
                                    name="notes"
                                    maxLength={2000}
                                    defaultValue={occurrence?.notes ?? ''}
                                />
                            </FormField>

                            <DialogFooter>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {editing
                                        ? 'Salvar alterações'
                                        : 'Cadastrar'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function DueDateField({
    defaultValue,
    error,
    hint,
}: {
    defaultValue: string;
    error?: string;
    hint?: string;
}) {
    return (
        <FormField
            id="expense-due"
            label="Vencimento"
            error={error}
            hint={hint}
        >
            <Input
                id="expense-due"
                name="due_date"
                type="date"
                required
                defaultValue={defaultValue}
                aria-invalid={!!error}
            />
        </FormField>
    );
}
