import { Form } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { CategorySelect } from '@/components/finance/category-select';
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
import { centsToDecimal, formatMoney } from '@/lib/format';
import expenses from '@/routes/expenses';
import type { Category, Occurrence, Scope } from '@/types';

/**
 * Creates a one-off expense, or edits a single monthly occurrence of any
 * expense (one-off, recurring month or card installment).
 */
export function ExpenseFormDialog({
    trigger,
    categories,
    month,
    scope = 'household',
    occurrence,
}: {
    trigger: ReactNode;
    categories: Category[];
    month: string;
    scope?: Scope;
    occurrence?: Occurrence;
}) {
    const [open, setOpen] = useState(false);
    const editing = occurrence !== undefined;
    const isInstallment = occurrence?.source === 'credit_card';
    const isOneOff = !editing || occurrence.source === 'one_off';
    const form = editing
        ? expenses.update.form(occurrence.id)
        : expenses.store.form();

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {editing ? 'Editar despesa' : 'Nova despesa avulsa'}
                    </DialogTitle>
                    <DialogDescription>
                        {editing && occurrence.source === 'recurring'
                            ? 'A alteração vale só para este mês. Para mudar os próximos meses, edite a despesa recorrente.'
                            : editing && isInstallment
                              ? 'O valor das parcelas é definido pela compra no cartão.'
                              : 'Para contas que se repetem todo mês, use Despesas recorrentes.'}
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
                                label="Descrição"
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
                                    label="Valor"
                                    error={errors.amount}
                                >
                                    {isInstallment ? (
                                        <>
                                            <Input
                                                id="expense-amount"
                                                disabled
                                                value={formatMoney(
                                                    occurrence.amount_cents,
                                                )}
                                            />
                                            <input
                                                type="hidden"
                                                name="amount"
                                                value={centsToDecimal(
                                                    occurrence.amount_cents,
                                                )}
                                            />
                                        </>
                                    ) : (
                                        <MoneyInput
                                            id="expense-amount"
                                            name="amount"
                                            required
                                            defaultCents={
                                                occurrence?.amount_cents
                                            }
                                            aria-invalid={!!errors.amount}
                                        />
                                    )}
                                </FormField>

                                <FormField
                                    id="expense-category"
                                    label="Categoria"
                                    error={errors.expense_category_id}
                                >
                                    <CategorySelect
                                        id="expense-category"
                                        categories={categories}
                                        defaultValue={occurrence?.category?.id}
                                        invalid={!!errors.expense_category_id}
                                    />
                                </FormField>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <FormField
                                    id="expense-month"
                                    label="Mês de referência"
                                    error={errors.billing_month}
                                    hint={
                                        isOneOff
                                            ? 'Mês em que a despesa entra no orçamento.'
                                            : 'Definido pela recorrência.'
                                    }
                                >
                                    <Input
                                        id="expense-month"
                                        name="billing_month"
                                        type="month"
                                        required
                                        readOnly={!isOneOff}
                                        defaultValue={
                                            occurrence?.billing_month ?? month
                                        }
                                        aria-invalid={!!errors.billing_month}
                                    />
                                </FormField>

                                <FormField
                                    id="expense-due"
                                    label="Vencimento"
                                    error={errors.due_date}
                                >
                                    <Input
                                        id="expense-due"
                                        name="due_date"
                                        type="date"
                                        required
                                        defaultValue={
                                            occurrence?.due_date ??
                                            `${month}-10`
                                        }
                                        aria-invalid={!!errors.due_date}
                                    />
                                </FormField>
                            </div>

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
