import { Form, Head, Link } from '@inertiajs/react';
import {
    CalendarOff,
    ChevronDown,
    Info,
    Pencil,
    Plus,
    Repeat,
    Trash2,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/finance/confirm-dialog';
import { EmptyState } from '@/components/finance/empty-state';
import { FormField } from '@/components/finance/form-field';
import { Money } from '@/components/finance/money';
import { MoneyInput } from '@/components/finance/money-input';
import { PageHeader } from '@/components/finance/page-header';
import { Pagination } from '@/components/finance/pagination';
import { ScopeField } from '@/components/finance/scope-field';
import { StatusBadge } from '@/components/finance/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import {
    formatDate,
    formatDateTime,
    formatMonth,
    formatShortMonth,
    shiftMonth,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import recurringExpenses from '@/routes/recurring-expenses';
import type { OccurrenceStatus, Paginated, Scope } from '@/types';

type Kind = 'fixed' | 'temporary';

type Template = {
    id: number;
    kind: Kind;
    scope: Scope;
    title: string;
    notes: string | null;
    amount_cents: number;
    is_variable: boolean;
    due_day: number;
    start_month: string;
    end_month: string | null;
    total_months: number | null;
    is_active: boolean;
    paid_count: number;
    generated_count: number;
    created_by: string;
    created_at: string | null;
    updated_by: string | null;
    updated_at: string | null;
    occurrences: {
        id: number;
        billing_month: string;
        due_date: string;
        amount_cents: number;
        status: OccurrenceStatus;
        is_customized: boolean;
    }[];
};

type Props = {
    filters: { kind: Kind | null; status: 'active' | 'ended' };
    currentMonth: string;
    templates: Paginated<Template>;
};

export default function RecurringExpensesIndex({
    filters,
    currentMonth,
    templates,
}: Props) {
    const tabs: { label: string; kind: Kind | null }[] = [
        { label: 'Todas', kind: null },
        { label: 'Fixas', kind: 'fixed' },
        { label: 'Extras temporárias', kind: 'temporary' },
    ];

    return (
        <>
            <Head title="Despesas recorrentes" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Despesas recorrentes"
                    description="Cadastre uma vez e o sistema gera cada mês com valor e pagamento independentes."
                >
                    <RecurringFormDialog
                        kind="fixed"
                        currentMonth={currentMonth}
                        trigger={
                            <Button>
                                <Plus />
                                Despesa fixa
                            </Button>
                        }
                    />
                    <RecurringFormDialog
                        kind="temporary"
                        currentMonth={currentMonth}
                        trigger={
                            <Button variant="outline">
                                <Plus />
                                Extra temporária
                            </Button>
                        }
                    />
                </PageHeader>

                <Alert>
                    <Info />
                    <AlertDescription>
                        <p>
                            <strong>Fixas</strong> repetem o mesmo valor todo
                            mês, sem prazo ou até um mês final.{' '}
                            <strong>Extras temporárias</strong> cobram um valor
                            mensal fixo por um número de meses. Compras
                            parceladas são cadastradas como despesa avulsa,
                            informando o número de parcelas.
                        </p>
                    </AlertDescription>
                </Alert>

                <div className="flex flex-wrap items-center gap-3">
                    <nav
                        aria-label="Tipo"
                        className="inline-flex rounded-lg bg-muted p-1 text-sm"
                    >
                        {tabs.map((tab) => (
                            <Link
                                key={tab.label}
                                href={recurringExpenses.index({
                                    query: {
                                        kind: tab.kind,
                                        status: filters.status,
                                    },
                                })}
                                preserveScroll
                                aria-current={
                                    filters.kind === tab.kind
                                        ? 'page'
                                        : undefined
                                }
                                className={cn(
                                    'rounded-md px-3 py-1.5 font-medium',
                                    filters.kind === tab.kind
                                        ? 'bg-background shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {tab.label}
                            </Link>
                        ))}
                    </nav>
                    <nav
                        aria-label="Situação"
                        className="inline-flex rounded-lg bg-muted p-1 text-sm"
                    >
                        {(
                            [
                                ['active', 'Ativas'],
                                ['ended', 'Encerradas'],
                            ] as const
                        ).map(([status, label]) => (
                            <Link
                                key={status}
                                href={recurringExpenses.index({
                                    query: { kind: filters.kind, status },
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
                </div>

                {templates.data.length === 0 ? (
                    <EmptyState
                        icon={Repeat}
                        title="Nenhuma despesa recorrente"
                        description="Cadastre contas que se repetem, como Internet, IPTU ou seguro, ou extras com prazo definido."
                    />
                ) : (
                    <div className="space-y-3">
                        {templates.data.map((template) => (
                            <TemplateCard
                                key={template.id}
                                template={template}
                                currentMonth={currentMonth}
                            />
                        ))}
                        <Pagination page={templates} />
                    </div>
                )}
            </div>
        </>
    );
}

function TemplateCard({
    template,
    currentMonth,
}: {
    template: Template;
    currentMonth: string;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Card className="py-4">
            <Collapsible open={open} onOpenChange={setOpen}>
                <CardContent className="space-y-3 px-4">
                    <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                        <div className="space-y-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <h2 className="font-semibold">
                                    {template.title}
                                </h2>
                                <Badge variant="outline">
                                    {template.kind === 'fixed'
                                        ? 'Fixa'
                                        : 'Extra temporária'}
                                </Badge>
                                <Badge variant="secondary">
                                    {template.scope === 'household'
                                        ? 'Casa'
                                        : 'Pessoal'}
                                </Badge>
                                {!template.is_active && (
                                    <Badge variant="outline">Encerrada</Badge>
                                )}
                            </div>
                            <p className="text-sm text-muted-foreground">
                                {template.is_variable ? (
                                    'Valor variável, informado a cada mês'
                                ) : (
                                    <>
                                        <Money cents={template.amount_cents} />{' '}
                                        por mês
                                    </>
                                )}{' '}
                                · vence todo dia {template.due_day}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {periodLabel(template)}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {template.paid_count} de{' '}
                                {template.generated_count} mês(es) gerado(s)
                                pago(s) · criado por {template.created_by}
                                {template.created_at &&
                                    ` em ${formatDateTime(template.created_at)}`}
                                {template.updated_by &&
                                    template.updated_at &&
                                    ` · editado por ${template.updated_by} em ${formatDateTime(template.updated_at)}`}
                            </p>
                        </div>
                        <div className="flex flex-wrap items-center gap-1">
                            <RecurringFormDialog
                                kind={template.kind}
                                template={template}
                                currentMonth={currentMonth}
                                trigger={
                                    <Button size="sm" variant="outline">
                                        <Pencil />
                                        Editar
                                    </Button>
                                }
                            />
                            {template.is_active && (
                                <EndDialog
                                    template={template}
                                    currentMonth={currentMonth}
                                />
                            )}
                            {template.paid_count === 0 && (
                                <ConfirmDialog
                                    url={recurringExpenses.destroy.url(
                                        template.id,
                                    )}
                                    title="Excluir despesa recorrente?"
                                    description={`“${template.title}” e todos os seus meses pendentes serão excluídos.`}
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

                    {template.notes && (
                        <p className="text-sm whitespace-pre-line">
                            {template.notes}
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
                            {open ? 'Ocultar meses' : 'Ver meses gerados'}
                        </Button>
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                            {template.occurrences.map((occurrence) => (
                                <li
                                    key={occurrence.id}
                                    className="flex items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm"
                                >
                                    <div>
                                        <div className="font-medium capitalize">
                                            {formatShortMonth(
                                                occurrence.billing_month,
                                            )}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            vence{' '}
                                            {formatDate(occurrence.due_date)}
                                            {occurrence.is_customized &&
                                                ' · ajustada'}
                                        </div>
                                    </div>
                                    <div className="text-right">
                                        {occurrence.status === 'pending' &&
                                        occurrence.amount_cents === 0 ? (
                                            <span className="block text-amber-700 dark:text-amber-400">
                                                A definir
                                            </span>
                                        ) : (
                                            <Money
                                                cents={occurrence.amount_cents}
                                                className="block"
                                            />
                                        )}
                                        <StatusBadge
                                            status={occurrence.status}
                                        />
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </CollapsibleContent>
                </CardContent>
            </Collapsible>
        </Card>
    );
}

function periodLabel(template: Template): string {
    const start = formatMonth(template.start_month);

    if (template.kind === 'temporary' && template.end_month) {
        return `${template.total_months} mês(es): de ${start} até ${formatMonth(template.end_month)}`;
    }

    return template.end_month
        ? `De ${start} até ${formatMonth(template.end_month)}`
        : `Desde ${start}, sem data de término`;
}

function RecurringFormDialog({
    kind,
    template,
    currentMonth,
    trigger,
}: {
    kind: Kind;
    template?: Template;
    currentMonth: string;
    trigger: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const editing = template !== undefined;
    const [startMonth, setStartMonth] = useState(
        template?.start_month ?? currentMonth,
    );
    const [totalMonths, setTotalMonths] = useState(template?.total_months ?? 3);
    const [variable, setVariable] = useState(template?.is_variable ?? false);
    const form = editing
        ? recurringExpenses.update.form(template.id)
        : recurringExpenses.store.form();
    const effectiveDefault =
        template && template.start_month > currentMonth
            ? template.start_month
            : currentMonth;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {editing
                            ? `Editar “${template.title}”`
                            : kind === 'fixed'
                              ? 'Nova despesa fixa'
                              : 'Nova despesa extra temporária'}
                    </DialogTitle>
                    <DialogDescription>
                        {editing
                            ? 'As mudanças valem para os meses pendentes a partir do mês escolhido. Meses pagos, anteriores ou ajustados individualmente não mudam.'
                            : kind === 'fixed'
                              ? 'Mesmo valor todo mês, indefinidamente ou até um mês final. Para contas que mudam todo mês, como a luz, marque “Valor variável”.'
                              : 'Valor mensal fixo cobrado por um número de meses. Ex.: R$ 300,00 por 4 meses gera 4 cobranças de R$ 300,00.'}
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
                                <>
                                    <input
                                        type="hidden"
                                        name="kind"
                                        value={kind}
                                    />
                                    <ScopeField error={errors.scope} />
                                </>
                            )}

                            <FormField
                                id="recurring-title"
                                label="Título"
                                error={errors.title}
                            >
                                <Input
                                    id="recurring-title"
                                    name="title"
                                    required
                                    maxLength={120}
                                    defaultValue={template?.title}
                                    placeholder={
                                        kind === 'fixed'
                                            ? 'Ex.: Internet'
                                            : 'Ex.: Sofá novo'
                                    }
                                />
                            </FormField>

                            <div className="space-y-2">
                                <div className="flex items-start gap-3">
                                    <Checkbox
                                        id="recurring-variable"
                                        checked={variable}
                                        onCheckedChange={(checked) =>
                                            setVariable(checked === true)
                                        }
                                    />
                                    <input
                                        type="hidden"
                                        name="is_variable"
                                        value={variable ? '1' : '0'}
                                    />
                                    <div className="grid gap-1">
                                        <Label htmlFor="recurring-variable">
                                            Valor variável
                                        </Label>
                                        <p className="text-xs text-muted-foreground">
                                            Para contas que mudam todo mês, como
                                            a luz. Cada mês aparece em Despesas
                                            como “Valor a definir” até você
                                            informar o valor.
                                        </p>
                                    </div>
                                </div>
                                {errors.is_variable && (
                                    <p className="text-sm text-destructive">
                                        {errors.is_variable}
                                    </p>
                                )}
                            </div>

                            {!variable && (
                                <FormField
                                    id="recurring-amount"
                                    label="Valor mensal"
                                    error={errors.amount}
                                >
                                    <MoneyInput
                                        id="recurring-amount"
                                        name="amount"
                                        required
                                        defaultCents={
                                            template?.is_variable
                                                ? null
                                                : template?.amount_cents
                                        }
                                    />
                                </FormField>
                            )}

                            <div className="grid gap-4 sm:grid-cols-2">
                                <FormField
                                    id="recurring-due-day"
                                    label="Dia de vencimento"
                                    error={errors.due_day}
                                    hint="Em meses mais curtos, vence no último dia."
                                >
                                    <Input
                                        id="recurring-due-day"
                                        name="due_day"
                                        type="number"
                                        min={1}
                                        max={31}
                                        required
                                        defaultValue={template?.due_day ?? 10}
                                    />
                                </FormField>
                                {editing ? (
                                    <FormField
                                        id="recurring-start"
                                        label="Mês de início"
                                    >
                                        <Input
                                            id="recurring-start"
                                            value={formatMonth(
                                                template.start_month,
                                            )}
                                            disabled
                                        />
                                    </FormField>
                                ) : (
                                    <FormField
                                        id="recurring-start"
                                        label="Mês de início"
                                        error={errors.start_month}
                                    >
                                        <Input
                                            id="recurring-start"
                                            name="start_month"
                                            type="month"
                                            required
                                            value={startMonth}
                                            onChange={(event) =>
                                                setStartMonth(
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </FormField>
                                )}
                            </div>

                            {kind === 'fixed' ? (
                                <FormField
                                    id="recurring-end"
                                    label="Mês final (opcional)"
                                    error={errors.end_month}
                                    hint="Deixe em branco para repetir indefinidamente."
                                >
                                    <Input
                                        id="recurring-end"
                                        name="end_month"
                                        type="month"
                                        min={startMonth}
                                        defaultValue={template?.end_month ?? ''}
                                    />
                                </FormField>
                            ) : (
                                <FormField
                                    id="recurring-total"
                                    label="Quantidade de meses"
                                    error={errors.total_months}
                                    hint={
                                        totalMonths > 0
                                            ? `Última cobrança em ${formatMonth(shiftMonth(startMonth, totalMonths - 1))}.`
                                            : undefined
                                    }
                                >
                                    <Input
                                        id="recurring-total"
                                        name="total_months"
                                        type="number"
                                        min={1}
                                        max={120}
                                        required
                                        value={totalMonths}
                                        onChange={(event) =>
                                            setTotalMonths(
                                                Number(event.target.value),
                                            )
                                        }
                                    />
                                </FormField>
                            )}

                            {editing && (
                                <FormField
                                    id="recurring-effective"
                                    label="Aplicar alterações a partir de"
                                    error={errors.effective_month}
                                >
                                    <Input
                                        id="recurring-effective"
                                        name="effective_month"
                                        type="month"
                                        required
                                        defaultValue={effectiveDefault}
                                    />
                                </FormField>
                            )}

                            <FormField
                                id="recurring-notes"
                                label="Observações"
                                error={errors.notes}
                            >
                                <Textarea
                                    id="recurring-notes"
                                    name="notes"
                                    maxLength={2000}
                                    defaultValue={template?.notes ?? ''}
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

function EndDialog({
    template,
    currentMonth,
}: {
    template: Template;
    currentMonth: string;
}) {
    const [open, setOpen] = useState(false);
    const defaultMonth =
        template.start_month > currentMonth
            ? template.start_month
            : currentMonth;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline">
                    <CalendarOff />
                    Encerrar
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Encerrar “{template.title}”</DialogTitle>
                    <DialogDescription>
                        Nenhum mês depois do último mês escolhido será gerado.
                        Meses pendentes posteriores são removidos; meses pagos
                        permanecem no histórico.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...recurringExpenses.end.form(template.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <FormField
                                id={`end-month-${template.id}`}
                                label="Último mês cobrado"
                                error={errors.end_month}
                            >
                                <Input
                                    id={`end-month-${template.id}`}
                                    name="end_month"
                                    type="month"
                                    required
                                    min={template.start_month}
                                    max={template.end_month ?? undefined}
                                    defaultValue={defaultMonth}
                                />
                            </FormField>
                            <DialogFooter>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    Encerrar
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

RecurringExpensesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Despesas recorrentes',
            href: recurringExpenses.index(),
        },
    ],
};
