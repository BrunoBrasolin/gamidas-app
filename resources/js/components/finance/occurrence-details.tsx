import { Info } from 'lucide-react';
import { Money } from '@/components/finance/money';
import { StatusBadge } from '@/components/finance/status-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    formatDate,
    formatDateTime,
    formatMonth,
    formatPercent,
} from '@/lib/format';
import type { Member, Occurrence } from '@/types';

/**
 * Shows who created, edited and paid an expense, how its shares were
 * calculated and its payment history.
 */
export function OccurrenceDetails({
    occurrence,
    members,
}: {
    occurrence: Occurrence;
    members: Member[];
}) {
    const nameOf = (userId: number) =>
        members.find((member) => member.user_id === userId)?.first_name ??
        'Ex-membro';

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button
                    size="icon"
                    variant="ghost"
                    className="size-8"
                    aria-label={`Detalhes de ${occurrence.title}`}
                >
                    <Info className="size-4" />
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{occurrence.title}</DialogTitle>
                    <DialogDescription>
                        {occurrence.source_label} ·{' '}
                        {formatMonth(occurrence.billing_month)}
                    </DialogDescription>
                </DialogHeader>

                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                    <dt className="text-muted-foreground">Valor</dt>
                    <dd>
                        <Money cents={occurrence.amount_cents} />
                    </dd>
                    <dt className="text-muted-foreground">Vencimento</dt>
                    <dd>{formatDate(occurrence.due_date)}</dd>
                    <dt className="text-muted-foreground">Situação</dt>
                    <dd>
                        <StatusBadge
                            status={occurrence.status}
                            overdue={occurrence.is_overdue}
                        />
                    </dd>
                    {occurrence.installment_number && (
                        <>
                            <dt className="text-muted-foreground">Parcela</dt>
                            <dd>
                                {occurrence.installment_number}/
                                {occurrence.installment_count}
                            </dd>
                        </>
                    )}
                    <dt className="text-muted-foreground">Criado por</dt>
                    <dd>
                        {occurrence.created_by.name}
                        {occurrence.created_at &&
                            ` em ${formatDateTime(occurrence.created_at)}`}
                    </dd>
                    {occurrence.updated_by && occurrence.updated_at && (
                        <>
                            <dt className="text-muted-foreground">
                                Última edição
                            </dt>
                            <dd>
                                {occurrence.updated_by.name} em{' '}
                                {formatDateTime(occurrence.updated_at)}
                            </dd>
                        </>
                    )}
                    {occurrence.paid_by && occurrence.paid_at && (
                        <>
                            <dt className="text-muted-foreground">Pagamento</dt>
                            <dd>
                                {occurrence.payment.paid_label} em{' '}
                                {formatDateTime(occurrence.paid_at)}
                            </dd>
                        </>
                    )}
                    {occurrence.notes && (
                        <>
                            <dt className="text-muted-foreground">
                                Observações
                            </dt>
                            <dd className="whitespace-pre-line">
                                {occurrence.notes}
                            </dd>
                        </>
                    )}
                </dl>

                {occurrence.shares.length > 0 && (
                    <section className="space-y-2">
                        <h3 className="text-sm font-medium">
                            Divisão proporcional
                            {occurrence.status === 'paid' &&
                                ' (congelada no pagamento)'}
                        </h3>
                        <ul className="space-y-1 text-sm">
                            {occurrence.shares.map((share) => (
                                <li
                                    key={share.user_id}
                                    className="flex justify-between gap-4"
                                >
                                    <span>
                                        {nameOf(share.user_id)}{' '}
                                        <span className="text-muted-foreground">
                                            {share.combined_salary_cents > 0
                                                ? `(${formatPercent(Math.round((share.salary_cents * 10000) / share.combined_salary_cents))} dos salários)`
                                                : '(divisão igual: salários não informados)'}
                                        </span>
                                    </span>
                                    <Money cents={share.amount_cents} />
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {occurrence.history && occurrence.history.length > 0 && (
                    <section className="space-y-2">
                        <h3 className="text-sm font-medium">
                            Histórico de pagamento
                        </h3>
                        <ol className="space-y-1 text-sm">
                            {occurrence.history.map((event) => (
                                <li key={event.id}>
                                    <span className="text-muted-foreground">
                                        {formatDateTime(event.created_at)}
                                    </span>{' '}
                                    —{' '}
                                    {event.type === 'paid'
                                        ? `pagamento confirmado por ${event.user}`
                                        : `pagamento reaberto por ${event.user}: “${event.reason}”`}
                                </li>
                            ))}
                        </ol>
                    </section>
                )}
            </DialogContent>
        </Dialog>
    );
}
