import { Form, Head } from '@inertiajs/react';
import { History, Info } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { FormField } from '@/components/finance/form-field';
import { Money } from '@/components/finance/money';
import { MoneyInput } from '@/components/finance/money-input';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, formatMoney, formatPercent } from '@/lib/format';
import household from '@/routes/household';
import type { Member } from '@/types';

type Props = {
    members: Member[];
    combinedSalaryCents: number;
    proportions: Record<number, number>;
    history: {
        id: number;
        user: string | null;
        old: Record<number, number>;
        new: Record<number, number>;
        recalculated: number;
        created_at: string;
    }[];
};

export default function Salaries({
    members,
    combinedSalaryCents,
    proportions,
    history,
}: Props) {
    const [draft, setDraft] = useState<Record<number, number>>(() =>
        Object.fromEntries(
            members.map((member) => [member.user_id, member.salary_cents]),
        ),
    );
    const draftTotal = Object.values(draft).reduce(
        (sum, cents) => sum + cents,
        0,
    );
    const changed = members.some(
        (member) => draft[member.user_id] !== member.salary_cents,
    );
    const nameOf = (userId: string) =>
        members.find((member) => String(member.user_id) === userId)
            ?.first_name ?? 'Ex-membro';

    return (
        <>
            <Head title="Salários" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Salários e proporções"
                    description="As despesas da casa são divididas proporcionalmente aos salários configurados aqui."
                />

                <div className="grid gap-4 sm:grid-cols-3">
                    {members.map((member) => (
                        <Card key={member.user_id} className="gap-1 py-4">
                            <CardContent className="px-4">
                                <div className="text-sm text-muted-foreground">
                                    {member.first_name}
                                </div>
                                <div className="text-xl font-semibold">
                                    <Money cents={member.salary_cents} />
                                </div>
                                <div className="text-sm">
                                    {formatPercent(
                                        proportions[member.user_id] ?? 0,
                                    )}{' '}
                                    das despesas da casa
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                    <Card className="gap-1 py-4">
                        <CardContent className="px-4">
                            <div className="text-sm text-muted-foreground">
                                Renda combinada
                            </div>
                            <div className="text-xl font-semibold">
                                <Money cents={combinedSalaryCents} />
                            </div>
                            <div className="text-sm text-muted-foreground">
                                {combinedSalaryCents === 0
                                    ? 'Sem salários: divisão igual'
                                    : 'Base da divisão'}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Alert>
                    <Info />
                    <AlertDescription>
                        <p>
                            Ao salvar, todas as despesas da casa{' '}
                            <strong>pendentes</strong> são recalculadas com os
                            novos salários. Despesas <strong>pagas</strong>{' '}
                            mantêm a divisão registrada no momento do pagamento.
                            Centavos de arredondamento vão para quem tem a maior
                            fração, garantindo que a soma das partes seja sempre
                            igual ao valor da conta.
                        </p>
                    </AlertDescription>
                </Alert>

                <Form
                    {...household.salaries.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                {members.map((member, index) => (
                                    <FormField
                                        key={member.user_id}
                                        id={`salary-${member.user_id}`}
                                        label={`Salário mensal de ${member.first_name}`}
                                        error={
                                            errors[`salaries.${index}.amount`]
                                        }
                                        hint={
                                            draftTotal > 0
                                                ? `Prévia: ${formatPercent(Math.round(((draft[member.user_id] ?? 0) * 10000) / draftTotal))}`
                                                : 'Prévia: divisão igual'
                                        }
                                    >
                                        <input
                                            type="hidden"
                                            name={`salaries[${index}][user_id]`}
                                            value={member.user_id}
                                        />
                                        <MoneyInput
                                            id={`salary-${member.user_id}`}
                                            name={`salaries[${index}][amount]`}
                                            defaultCents={member.salary_cents}
                                            onCentsChange={(cents) =>
                                                setDraft((current) => ({
                                                    ...current,
                                                    [member.user_id]: cents,
                                                }))
                                            }
                                            required
                                        />
                                    </FormField>
                                ))}
                            </div>
                            <p className="text-sm text-muted-foreground">
                                Renda combinada após salvar:{' '}
                                {formatMoney(draftTotal)}
                            </p>
                            <Button
                                type="submit"
                                disabled={processing || !changed}
                            >
                                {processing && <Spinner />}
                                Salvar salários
                            </Button>
                        </>
                    )}
                </Form>
            </div>

            <div className="space-y-4">
                <Heading
                    variant="small"
                    title="Histórico de alterações"
                    description="Registro das últimas mudanças de salário"
                />
                {history.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Nenhuma alteração registrada.
                    </p>
                ) : (
                    <ol className="space-y-3">
                        {history.map((entry) => (
                            <li
                                key={entry.id}
                                className="flex gap-3 rounded-md border p-3 text-sm"
                            >
                                <History className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                <div className="space-y-1">
                                    <div>
                                        <strong>
                                            {entry.user ?? 'Sistema'}
                                        </strong>{' '}
                                        em {formatDateTime(entry.created_at)} ·{' '}
                                        {entry.recalculated} despesa(s)
                                        pendente(s) recalculada(s)
                                    </div>
                                    <ul className="text-muted-foreground">
                                        {Object.keys(entry.new)
                                            .filter(
                                                (userId) =>
                                                    entry.old[
                                                        Number(userId)
                                                    ] !==
                                                    entry.new[Number(userId)],
                                            )
                                            .map((userId) => (
                                                <li key={userId}>
                                                    {nameOf(userId)}:{' '}
                                                    {formatMoney(
                                                        entry.old[
                                                            Number(userId)
                                                        ] ?? 0,
                                                    )}{' '}
                                                    →{' '}
                                                    {formatMoney(
                                                        entry.new[
                                                            Number(userId)
                                                        ],
                                                    )}
                                                </li>
                                            ))}
                                    </ul>
                                </div>
                            </li>
                        ))}
                    </ol>
                )}
            </div>
        </>
    );
}

Salaries.layout = {
    breadcrumbs: [
        {
            title: 'Configurações da casa',
            href: household.salaries.edit(),
        },
    ],
};
