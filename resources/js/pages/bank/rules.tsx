import { Form, Head } from '@inertiajs/react';
import { Pencil, Plus, SlidersHorizontal, Trash2, Zap } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/finance/confirm-dialog';
import { EmptyState } from '@/components/finance/empty-state';
import { FormField } from '@/components/finance/form-field';
import { MoneyInput } from '@/components/finance/money-input';
import { PageHeader } from '@/components/finance/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, formatMoney } from '@/lib/format';
import bank from '@/routes/bank';
import type { BankRule, BankRuleAction } from '@/types';

type Option = { value: string; label: string };

type Props = {
    rules: BankRule[];
    actions: Option[];
    matchModes: Option[];
    recurringExpenses: { id: number; title: string; scope: string }[];
};

export default function BankRules({
    rules,
    actions,
    matchModes,
    recurringExpenses,
}: Props) {
    const [search, setSearch] = useState('');
    const visible = rules.filter((rule) =>
        `${rule.label} ${rule.title ?? ''} ${rule.recurring_expense?.title ?? ''}`
            .toLocaleLowerCase('pt-BR')
            .includes(search.toLocaleLowerCase('pt-BR')),
    );

    const formProps = { actions, matchModes, recurringExpenses };

    return (
        <>
            <Head title="Regras de catalogação" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Regras de catalogação"
                    description="O que a caixa de entrada aprendeu com você: quando uma transação bate com a regra, ela já vem com a sugestão (ou é catalogada sozinha, se marcado)."
                >
                    <RuleDialog
                        {...formProps}
                        trigger={
                            <Button>
                                <Plus />
                                Nova regra
                            </Button>
                        }
                    />
                </PageHeader>

                {rules.length === 0 ? (
                    <EmptyState
                        icon={SlidersHorizontal}
                        title="Nenhuma regra ainda"
                        description="Ao catalogar uma transação na caixa de entrada com “Lembrar” marcado, a regra aparece aqui. Ex.: PIX para Fulano → Aluguel."
                    />
                ) : (
                    <>
                        <Input
                            type="search"
                            placeholder="Buscar regra"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            className="max-w-sm"
                            aria-label="Buscar regra"
                        />
                        <div className="space-y-2">
                            {visible.map((rule) => (
                                <Card key={rule.id} className="py-3">
                                    <CardContent className="flex flex-col gap-2 px-4 sm:flex-row sm:items-center sm:justify-between">
                                        <div className="min-w-0">
                                            <div className="font-medium break-words">
                                                {rule.match_mode === 'contains'
                                                    ? `Contém “${rule.label}”`
                                                    : rule.label}
                                                {rule.amount_cents !== null && (
                                                    <span className="text-muted-foreground">
                                                        {' '}
                                                        de{' '}
                                                        {formatMoney(
                                                            rule.amount_cents,
                                                        )}
                                                    </span>
                                                )}{' '}
                                                <span className="text-muted-foreground">
                                                    →
                                                </span>{' '}
                                                {destination(rule)}
                                            </div>
                                            <div className="mt-1 flex flex-wrap items-center gap-1 text-xs text-muted-foreground">
                                                <Badge variant="outline">
                                                    {rule.action_label}
                                                </Badge>
                                                {rule.auto_apply && (
                                                    <Badge variant="secondary">
                                                        <Zap className="size-3" />
                                                        Automática
                                                    </Badge>
                                                )}
                                                <span>
                                                    {rule.hits === 1
                                                        ? 'usada 1 vez'
                                                        : `usada ${rule.hits} vezes`}
                                                    {rule.last_used_at &&
                                                        ` · última em ${formatDateTime(rule.last_used_at)}`}
                                                </span>
                                            </div>
                                        </div>
                                        <div className="flex justify-end gap-0.5">
                                            <RuleDialog
                                                {...formProps}
                                                rule={rule}
                                                trigger={
                                                    <Button
                                                        size="icon"
                                                        variant="ghost"
                                                        className="size-8"
                                                        aria-label={`Editar regra ${rule.label}`}
                                                    >
                                                        <Pencil className="size-4" />
                                                    </Button>
                                                }
                                            />
                                            <ConfirmDialog
                                                url={bank.rules.destroy.url(
                                                    rule.id,
                                                )}
                                                title="Remover regra?"
                                                description={`As próximas transações de “${rule.label}” deixam de vir com sugestão. O que já foi catalogado não muda.`}
                                                confirmLabel="Remover"
                                                trigger={
                                                    <Button
                                                        size="icon"
                                                        variant="ghost"
                                                        className="size-8 text-destructive"
                                                        aria-label={`Remover regra ${rule.label}`}
                                                    >
                                                        <Trash2 className="size-4" />
                                                    </Button>
                                                }
                                            />
                                        </div>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    </>
                )}
            </div>
        </>
    );
}

function destination(rule: BankRule): string {
    if (rule.action === 'recurring') {
        return rule.recurring_expense?.title ?? 'despesa recorrente removida';
    }

    if (rule.action === 'ignore') {
        return 'ignorar';
    }

    if (rule.action === 'settlement') {
        return 'acerto da casa';
    }

    return rule.title
        ? `${rule.action_label}: ${rule.title}`
        : rule.action_label;
}

function RuleDialog({
    rule,
    trigger,
    actions,
    matchModes,
    recurringExpenses,
}: {
    rule?: BankRule;
    trigger: ReactNode;
    actions: Option[];
    matchModes: Option[];
    recurringExpenses: Props['recurringExpenses'];
}) {
    const [open, setOpen] = useState(false);
    const [action, setAction] = useState<BankRuleAction>(
        rule?.action ?? 'household',
    );
    const [autoApply, setAutoApply] = useState(rule?.auto_apply ?? false);
    const form = rule
        ? bank.rules.update.form(rule.id)
        : bank.rules.store.form();

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (next) {
                    setAction(rule?.action ?? 'household');
                    setAutoApply(rule?.auto_apply ?? false);
                }

                setOpen(next);
            }}
        >
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {rule ? 'Editar regra' : 'Nova regra'}
                    </DialogTitle>
                    <DialogDescription>
                        Compara com o nome de quem recebeu ou enviou (PIX,
                        transferência) ou com a descrição da compra, sem
                        diferenciar maiúsculas, acentos e números.
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
                            <div className="grid gap-4 sm:grid-cols-[10rem_1fr]">
                                <FormField
                                    id="rule-mode"
                                    label="Quando"
                                    error={errors.match_mode}
                                >
                                    <NativeSelect
                                        id="rule-mode"
                                        name="match_mode"
                                        defaultValue={
                                            rule?.match_mode ?? 'equals'
                                        }
                                    >
                                        {matchModes.map((mode) => (
                                            <option
                                                key={mode.value}
                                                value={mode.value}
                                            >
                                                {mode.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </FormField>
                                <FormField
                                    id="rule-label"
                                    label="Contraparte ou descrição"
                                    error={errors.label}
                                >
                                    <Input
                                        id="rule-label"
                                        name="label"
                                        required
                                        maxLength={160}
                                        defaultValue={rule?.label}
                                        placeholder="Ex.: Fulano de Tal, iFood"
                                    />
                                </FormField>
                            </div>
                            <FormField
                                id="rule-amount"
                                label="Valor (opcional)"
                                error={errors.amount}
                                hint="Preenchido, a regra só vale para transações de exatamente esse valor — útil quando a mesma pessoa recebe PIX de coisas diferentes. Vazio, vale para qualquer valor."
                            >
                                <MoneyInput
                                    id="rule-amount"
                                    name="amount"
                                    defaultCents={rule?.amount_cents ?? null}
                                    placeholder="Qualquer valor"
                                />
                            </FormField>
                            <FormField
                                id="rule-action"
                                label="Sugerir"
                                error={errors.action}
                            >
                                <NativeSelect
                                    id="rule-action"
                                    name="action"
                                    value={action}
                                    onChange={(event) =>
                                        setAction(
                                            event.target
                                                .value as BankRuleAction,
                                        )
                                    }
                                >
                                    {actions.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </FormField>
                            {action === 'recurring' ? (
                                <FormField
                                    id="rule-recurring"
                                    label="Despesa recorrente"
                                    error={errors.recurring_expense_id}
                                    hint="A transação é vinculada ao mês desta despesa mais próximo da data."
                                >
                                    <NativeSelect
                                        id="rule-recurring"
                                        name="recurring_expense_id"
                                        required
                                        defaultValue={
                                            rule?.recurring_expense?.id ?? ''
                                        }
                                    >
                                        <option value="" disabled>
                                            Escolha…
                                        </option>
                                        {recurringExpenses.map((expense) => (
                                            <option
                                                key={expense.id}
                                                value={expense.id}
                                            >
                                                {expense.title}
                                                {expense.scope === 'personal'
                                                    ? ' (pessoal)'
                                                    : ''}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </FormField>
                            ) : (
                                action !== 'ignore' &&
                                action !== 'settlement' && (
                                    <FormField
                                        id="rule-title"
                                        label="Título"
                                        error={errors.title}
                                        hint="Opcional. Sem título, usa o nome da transação."
                                    >
                                        <Input
                                            id="rule-title"
                                            name="title"
                                            maxLength={120}
                                            defaultValue={rule?.title ?? ''}
                                        />
                                    </FormField>
                                )
                            )}
                            <div className="flex items-start gap-2">
                                <input
                                    type="hidden"
                                    name="auto_apply"
                                    value={autoApply ? '1' : '0'}
                                />
                                <Checkbox
                                    id="rule-auto"
                                    checked={autoApply}
                                    onCheckedChange={(checked) =>
                                        setAutoApply(checked === true)
                                    }
                                />
                                <Label
                                    htmlFor="rule-auto"
                                    className="leading-snug font-normal"
                                >
                                    Catalogar sozinho, sem passar pela caixa de
                                    entrada
                                </Label>
                            </div>
                            <DialogFooter>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {rule ? 'Salvar' : 'Criar regra'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

BankRules.layout = {
    breadcrumbs: [
        {
            title: 'Regras de catalogação',
            href: bank.rules.index(),
        },
    ],
};
