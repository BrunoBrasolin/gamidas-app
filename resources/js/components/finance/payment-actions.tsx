import { Form, router } from '@inertiajs/react';
import { CheckCircle2, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import { FormField } from '@/components/finance/form-field';
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
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatDateTime } from '@/lib/format';
import expenses from '@/routes/expenses';
import type { Occurrence } from '@/types';

/**
 * The payment confirmation cell. Household expenses are confirmed by the
 * member who did not create them ("Pago pelo Bruno" for an expense created by
 * Letícia); the server enforces this, the UI only reflects it.
 */
export function PaymentActions({ occurrence }: { occurrence: Occurrence }) {
    const [processing, setProcessing] = useState(false);

    if (occurrence.status === 'paid') {
        return (
            <div className="flex items-center gap-1.5">
                <div className="text-xs leading-tight">
                    <div className="font-medium text-emerald-700 dark:text-emerald-400">
                        {occurrence.payment.paid_label}
                    </div>
                    {occurrence.paid_at && (
                        <div className="text-muted-foreground">
                            {formatDateTime(occurrence.paid_at)}
                        </div>
                    )}
                </div>
                {occurrence.payment.can_reopen && (
                    <ReopenPaymentDialog occurrence={occurrence} />
                )}
            </div>
        );
    }

    if (!occurrence.payment.can_confirm) {
        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    <span
                        tabIndex={0}
                        className="inline-flex cursor-help items-center rounded-md border border-dashed px-2 py-1 text-xs text-muted-foreground"
                    >
                        Aguardando “{occurrence.payment.confirm_label}”
                    </span>
                </TooltipTrigger>
                <TooltipContent>
                    Quem cadastrou a despesa não confirma o próprio pagamento.
                </TooltipContent>
            </Tooltip>
        );
    }

    return (
        <Button
            size="sm"
            variant="outline"
            disabled={processing}
            onClick={() =>
                router.post(
                    expenses.payment.store.url(occurrence.id),
                    {},
                    {
                        preserveScroll: true,
                        onStart: () => setProcessing(true),
                        onFinish: () => setProcessing(false),
                    },
                )
            }
        >
            {processing ? <Spinner /> : <CheckCircle2 />}
            {occurrence.payment.confirm_label}
        </Button>
    );
}

function ReopenPaymentDialog({ occurrence }: { occurrence: Occurrence }) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <Tooltip>
                <TooltipTrigger asChild>
                    <DialogTrigger asChild>
                        <Button
                            size="icon"
                            variant="ghost"
                            className="size-7"
                            aria-label="Reabrir pagamento"
                        >
                            <RotateCcw className="size-3.5" />
                        </Button>
                    </DialogTrigger>
                </TooltipTrigger>
                <TooltipContent>Reabrir pagamento</TooltipContent>
            </Tooltip>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Reabrir pagamento</DialogTitle>
                    <DialogDescription>
                        “{occurrence.title}” voltará a ficar pendente e passará
                        a seguir os salários atuais. A confirmação anterior
                        continua registrada no histórico.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...expenses.payment.destroy.form(occurrence.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <FormField
                                id={`reopen-reason-${occurrence.id}`}
                                label="Motivo"
                                error={errors.reason}
                            >
                                <Textarea
                                    id={`reopen-reason-${occurrence.id}`}
                                    name="reason"
                                    required
                                    minLength={3}
                                    maxLength={255}
                                    placeholder="Ex.: pagamento estornado pelo banco"
                                />
                            </FormField>
                            <DialogFooter>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    Reabrir pagamento
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
