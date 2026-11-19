import { Form, Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CreditCard,
    Inbox,
    KeyRound,
    Landmark,
    PiggyBank,
    Plus,
    RefreshCw,
    Trash2,
    Webhook,
} from 'lucide-react';
import { useState } from 'react';
import { PluggyConnectButton } from '@/components/bank/pluggy-connect-button';
import { ConfirmDialog } from '@/components/finance/confirm-dialog';
import { EmptyState } from '@/components/finance/empty-state';
import { FormField } from '@/components/finance/form-field';
import { Money } from '@/components/finance/money';
import { PageHeader } from '@/components/finance/page-header';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { formatDate, formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import bank from '@/routes/bank';
import type { BankAccount, BankConnection } from '@/types';

type Props = {
    configured: boolean;
    webhook: { configured: boolean; url: string };
    connectScript: string;
    connections: BankConnection[];
    inboxCount: number;
};

export default function BankAccounts({
    configured,
    webhook,
    connectScript,
    connections,
    inboxCount,
}: Props) {
    return (
        <>
            <Head title="Contas e cartões" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Contas e cartões"
                    description="Bancos conectados pelo Open Finance (Pluggy). Só você vê as suas contas e transações."
                >
                    {configured && (
                        <>
                            <ItemIdDialog />
                            <PluggyConnectButton script={connectScript}>
                                <Plus />
                                Conectar banco
                            </PluggyConnectButton>
                        </>
                    )}
                </PageHeader>

                {!configured && (
                    <Alert>
                        <KeyRound />
                        <AlertTitle>Pluggy não configurada</AlertTitle>
                        <AlertDescription>
                            <p>
                                Crie uma aplicação no painel da Pluggy e defina{' '}
                                <code className="rounded bg-muted px-1">
                                    PLUGGY_CLIENT_ID
                                </code>{' '}
                                e{' '}
                                <code className="rounded bg-muted px-1">
                                    PLUGGY_CLIENT_SECRET
                                </code>{' '}
                                no <code>.env</code> do servidor. Para uso
                                pessoal, o Meu Pluggy é gratuito (até 5
                                conexões).
                            </p>
                        </AlertDescription>
                    </Alert>
                )}

                {configured && !webhook.configured && (
                    <Alert>
                        <Webhook />
                        <AlertTitle>Webhook não configurado</AlertTitle>
                        <AlertDescription>
                            <p>
                                Sem o webhook, as transações chegam pela busca
                                automática a cada poucas horas. Para recebê-las
                                assim que a Pluggy atualizar, defina{' '}
                                <code className="rounded bg-muted px-1">
                                    PLUGGY_WEBHOOK_TOKEN
                                </code>{' '}
                                e rode{' '}
                                <code className="rounded bg-muted px-1">
                                    php artisan bank:webhook
                                </code>{' '}
                                no servidor.
                            </p>
                        </AlertDescription>
                    </Alert>
                )}

                {inboxCount > 0 && (
                    <Alert>
                        <Inbox />
                        <AlertTitle>
                            {inboxCount === 1
                                ? '1 transação para catalogar'
                                : `${inboxCount} transações para catalogar`}
                        </AlertTitle>
                        <AlertDescription>
                            <Link
                                href={bank.inbox()}
                                className="font-medium underline underline-offset-4"
                            >
                                Abrir a caixa de entrada
                            </Link>
                        </AlertDescription>
                    </Alert>
                )}

                {connections.length === 0 ? (
                    <EmptyState
                        icon={Landmark}
                        title="Nenhum banco conectado"
                        description="Conecte sua conta, cartão de crédito e investimentos. As movimentações (PIX, compras no cartão, transferências) chegam na caixa de entrada para você catalogar como despesa da casa, pessoal, receita ou investimento."
                    >
                        <MeuPluggySteps />
                    </EmptyState>
                ) : (
                    connections.map((connection) => (
                        <ConnectionCard
                            key={connection.id}
                            connection={connection}
                            connectScript={connectScript}
                        />
                    ))
                )}
            </div>
        </>
    );
}

function MeuPluggySteps() {
    return (
        <ol className="mx-auto max-w-lg list-decimal space-y-1 pl-5 text-left text-sm text-muted-foreground">
            <li>
                Em <strong>meu.pluggy.ai</strong>, conecte o Nubank pelo Open
                Finance (conta, cartão e investimentos).
            </li>
            <li>
                Clique em <strong>Conectar banco</strong> e escolha o conector{' '}
                <strong>MeuPluggy</strong>, ou copie o ID da conexão no painel
                da Pluggy (Applications › Demo › Connected Items) e use{' '}
                <strong>Adicionar pelo ID</strong>.
            </li>
            <li>
                As transações dos últimos 30 dias entram na caixa de entrada; as
                próximas chegam sozinhas.
            </li>
        </ol>
    );
}

/**
 * Attach an item created outside the widget: Meu Pluggy connections are
 * made in the Pluggy dashboard, which shows their id.
 */
function ItemIdDialog() {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline">Adicionar pelo ID</Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Adicionar conexão pelo ID</DialogTitle>
                    <DialogDescription>
                        Para conexões do Meu Pluggy criadas no painel da Pluggy
                        (Applications › Demo › Connected Items). Cada banco tem
                        o seu ID.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...bank.connections.store.form()}
                    onSuccess={() => setOpen(false)}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <FormField
                                id="item-id"
                                label="ID da conexão (item)"
                                error={errors.item_id}
                            >
                                <Input
                                    id="item-id"
                                    name="item_id"
                                    required
                                    maxLength={64}
                                    autoComplete="off"
                                    placeholder="00000000-0000-0000-0000-000000000000"
                                    aria-invalid={!!errors.item_id}
                                />
                            </FormField>
                            <DialogFooter>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    Conectar
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function ConnectionCard({
    connection,
    connectScript,
}: {
    connection: BankConnection;
    connectScript: string;
}) {
    const [syncing, setSyncing] = useState(false);
    const banks = connection.accounts.filter((a) => a.type === 'BANK');
    const cards = connection.accounts.filter((a) => a.type === 'CREDIT');

    function sync() {
        router.post(
            bank.connections.sync.url(connection.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setSyncing(true),
                onFinish: () => setSyncing(false),
            },
        );
    }

    return (
        <Card>
            <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="flex items-center gap-3">
                    <BankLogo connection={connection} />
                    <div className="min-w-0">
                        <CardTitle className="flex flex-wrap items-center gap-2">
                            {connection.connector_name}
                            <Badge
                                variant={
                                    connection.needs_reconnect
                                        ? 'destructive'
                                        : 'outline'
                                }
                            >
                                {connection.status_label}
                            </Badge>
                        </CardTitle>
                        <p className="text-xs text-muted-foreground">
                            {connection.synced_at
                                ? `Buscado em ${formatDateTime(connection.synced_at)}`
                                : 'Ainda não sincronizado'}
                            {connection.bank_updated_at &&
                                ` · banco atualizado em ${formatDateTime(connection.bank_updated_at)}`}
                        </p>
                    </div>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={sync}
                        disabled={syncing}
                    >
                        {syncing ? <Spinner /> : <RefreshCw />}
                        Buscar agora
                    </Button>
                    {!connection.is_meu_pluggy && (
                        <PluggyConnectButton
                            script={connectScript}
                            connectionId={connection.id}
                            variant="outline"
                            size="sm"
                        >
                            Reconectar
                        </PluggyConnectButton>
                    )}
                    <ConfirmDialog
                        url={bank.connections.destroy.url(connection.id)}
                        title="Desconectar banco?"
                        description={`A autorização de ${connection.connector_name} é revogada na Pluggy e as contas, faturas, investimentos e transações importadas são apagados daqui. As despesas e lançamentos que você já catalogou continuam.`}
                        confirmLabel="Desconectar e apagar"
                        trigger={
                            <Button
                                variant="ghost"
                                size="sm"
                                className="text-destructive"
                            >
                                <Trash2 />
                                Desconectar
                            </Button>
                        }
                    />
                </div>
            </CardHeader>
            <CardContent className="space-y-6">
                <ConnectionWarnings connection={connection} />

                {connection.accounts.length === 0 &&
                    connection.investments.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            Nenhuma conta recebida ainda. A Pluggy pode levar
                            alguns minutos na primeira atualização; use “Buscar
                            agora” depois.
                        </p>
                    )}

                {banks.length > 0 && (
                    <section className="space-y-2">
                        <h3 className="flex items-center gap-2 text-sm font-medium">
                            <Landmark className="size-4" aria-hidden />
                            Contas
                        </h3>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {banks.map((account) => (
                                <BankAccountTile
                                    key={account.id}
                                    account={account}
                                />
                            ))}
                        </div>
                    </section>
                )}

                {cards.length > 0 && (
                    <section className="space-y-2">
                        <h3 className="flex items-center gap-2 text-sm font-medium">
                            <CreditCard className="size-4" aria-hidden />
                            Cartões de crédito
                        </h3>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {cards.map((account) => (
                                <CreditCardTile
                                    key={account.id}
                                    account={account}
                                />
                            ))}
                        </div>
                    </section>
                )}

                {connection.investments.length > 0 && (
                    <section className="space-y-2">
                        <h3 className="flex items-center justify-between gap-2 text-sm font-medium">
                            <span className="flex items-center gap-2">
                                <PiggyBank className="size-4" aria-hidden />
                                Investimentos
                            </span>
                            <Money cents={connection.investments_total_cents} />
                        </h3>
                        <ul className="divide-y rounded-lg border text-sm">
                            {connection.investments.map((investment) => (
                                <li
                                    key={investment.id}
                                    className="flex items-start justify-between gap-3 px-3 py-2"
                                >
                                    <div className="min-w-0">
                                        <div className="font-medium">
                                            {investment.name}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {[
                                                investment.subtype ??
                                                    investment.type,
                                                investment.rate,
                                                investment.due_date &&
                                                    `vence ${formatDate(investment.due_date)}`,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </div>
                                    </div>
                                    <div className="text-right">
                                        <Money
                                            cents={investment.balance_cents}
                                            className="font-medium"
                                        />
                                        {investment.invested_cents !== null && (
                                            <div className="text-xs text-muted-foreground">
                                                aplicado{' '}
                                                <Money
                                                    cents={
                                                        investment.invested_cents
                                                    }
                                                />
                                            </div>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <p className="text-xs text-muted-foreground">
                    Importando transações desde{' '}
                    {formatDate(connection.import_from)}.
                    {connection.is_meu_pluggy &&
                        ' Conexão do Meu Pluggy: o banco é atualizado uma vez por dia e a autorização é renovada em meu.pluggy.ai.'}
                </p>
            </CardContent>
        </Card>
    );
}

function ConnectionWarnings({ connection }: { connection: BankConnection }) {
    if (connection.needs_reconnect) {
        return (
            <Alert variant="destructive">
                <AlertTriangle />
                <AlertTitle>O banco parou de enviar dados</AlertTitle>
                <AlertDescription>
                    <p>
                        {connection.error_message ??
                            'A autorização expirou ou foi revogada.'}{' '}
                        {connection.is_meu_pluggy
                            ? 'Renove a conexão em meu.pluggy.ai; se o ID mudar, adicione a nova conexão pelo ID e desconecte esta.'
                            : 'Use “Reconectar” para autorizar de novo.'}
                    </p>
                </AlertDescription>
            </Alert>
        );
    }

    return (
        <>
            {connection.consent_expiring && connection.consent_expires_at && (
                <Alert>
                    <AlertTriangle />
                    <AlertTitle>Autorização perto de expirar</AlertTitle>
                    <AlertDescription>
                        <p>
                            O consentimento do Open Finance vence em{' '}
                            {formatDateTime(connection.consent_expires_at)}.{' '}
                            {connection.is_meu_pluggy
                                ? 'Renove em meu.pluggy.ai.'
                                : 'Use “Reconectar” para renovar.'}
                        </p>
                    </AlertDescription>
                </Alert>
            )}
            {connection.sync_error && (
                <Alert variant="destructive">
                    <AlertTriangle />
                    <AlertTitle>A última busca falhou</AlertTitle>
                    <AlertDescription>
                        <p>{connection.sync_error}</p>
                    </AlertDescription>
                </Alert>
            )}
        </>
    );
}

function BankLogo({ connection }: { connection: BankConnection }) {
    if (connection.connector_image_url) {
        return (
            <img
                src={connection.connector_image_url}
                alt=""
                className="size-10 shrink-0 rounded-lg border bg-white object-contain p-1"
            />
        );
    }

    return (
        <span
            className="flex size-10 shrink-0 items-center justify-center rounded-lg text-white"
            style={{
                backgroundColor: connection.connector_color ?? '#4B5563',
            }}
            aria-hidden
        >
            <Landmark className="size-5" />
        </span>
    );
}

function BankAccountTile({ account }: { account: BankAccount }) {
    return (
        <div className="rounded-lg border p-3">
            <div className="text-sm text-muted-foreground">
                {account.name}
                {account.number && ` · ${account.number}`}
            </div>
            <Money
                cents={account.balance_cents}
                signed={account.balance_cents < 0}
                className="text-xl font-semibold"
            />
        </div>
    );
}

function CreditCardTile({ account }: { account: BankAccount }) {
    return (
        <div className="space-y-2 rounded-lg border p-3">
            <div className="text-sm text-muted-foreground">
                {account.name}
                {account.number && ` · final ${account.number}`}
            </div>
            <div className="flex items-end justify-between gap-2">
                <div>
                    <div className="text-xs text-muted-foreground">
                        Fatura atual
                    </div>
                    <Money
                        cents={account.balance_cents}
                        className="text-xl font-semibold"
                    />
                </div>
                {account.balance_due_date && (
                    <div className="text-right text-xs text-muted-foreground">
                        vence {formatDate(account.balance_due_date)}
                    </div>
                )}
            </div>
            {account.credit_limit_cents !== null && (
                <div className="text-xs text-muted-foreground">
                    Limite disponível{' '}
                    <Money cents={account.available_credit_cents ?? 0} /> de{' '}
                    <Money cents={account.credit_limit_cents} />
                </div>
            )}
            {account.bills.length > 0 && (
                <ul className="space-y-0.5 border-t pt-2 text-xs">
                    {account.bills.map((bill) => (
                        <li
                            key={bill.id}
                            className={cn('flex justify-between gap-2')}
                        >
                            <span className="text-muted-foreground">
                                Fatura de {formatDate(bill.due_date)}
                                {bill.finance_charges_cents > 0 && (
                                    <>
                                        {' '}
                                        (encargos{' '}
                                        <Money
                                            cents={bill.finance_charges_cents}
                                        />
                                        )
                                    </>
                                )}
                            </span>
                            <Money cents={bill.total_cents} />
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

BankAccounts.layout = {
    breadcrumbs: [
        {
            title: 'Contas e cartões',
            href: bank.index(),
        },
    ],
};
