import { router } from '@inertiajs/react';
import { Check, Copy, KeyRound, Plug, Unplug } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/finance/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { useClipboard } from '@/hooks/use-clipboard';
import { formatDateTime } from '@/lib/format';
import watched from '@/routes/watched';
import type { JellyfinConnection as Connection } from '@/types';

/** A read-only value with a button that copies it. */
function CopyField({ label, value }: { label: string; value: string }) {
    const [copied, copy] = useClipboard();
    const Icon = copied === value ? Check : Copy;

    return (
        <div className="space-y-1">
            <p className="text-xs text-muted-foreground">{label}</p>
            <div className="flex items-center gap-2">
                <code className="min-w-0 flex-1 truncate rounded-md border bg-muted px-2 py-1.5 font-mono text-xs">
                    {value}
                </code>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    aria-label={`Copiar ${label.toLowerCase()}`}
                    onClick={() => copy(value)}
                >
                    <Icon />
                </Button>
            </div>
        </div>
    );
}

/**
 * The Jellyfin webhook setup: the URL and token to paste in the Webhook
 * plugin, with the steps to configure it.
 */
export function JellyfinConnectionCard({ jellyfin }: { jellyfin: Connection }) {
    const [issuing, setIssuing] = useState(false);

    function issue() {
        router.post(
            watched.jellyfin.store.url(),
            {},
            {
                preserveScroll: true,
                onStart: () => setIssuing(true),
                onFinish: () => setIssuing(false),
            },
        );
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex flex-wrap items-center gap-2">
                    Jellyfin
                    {jellyfin.connected ? (
                        <Badge variant="secondary">Conectado</Badge>
                    ) : (
                        <Badge variant="outline">Não conectado</Badge>
                    )}
                </CardTitle>
                <CardDescription>
                    {jellyfin.connected
                        ? jellyfin.last_received_at
                            ? `Último evento recebido em ${formatDateTime(jellyfin.last_received_at)}.`
                            : 'Nenhum evento recebido ainda.'
                        : 'O plugin Webhook do Jellyfin avisa o Gamidas quando alguém da casa começa, para ou termina um filme ou episódio. Uma conexão vale para a casa toda.'}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {jellyfin.new_token && (
                    <div className="space-y-3 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3">
                        <p className="text-sm">
                            Copie o token agora: ele não será mostrado de novo.
                            Gerar outro desativa este.
                        </p>
                        <CopyField label="Token" value={jellyfin.new_token} />
                    </div>
                )}

                <CopyField
                    label="URL do webhook"
                    value={jellyfin.webhook_url}
                />

                <ol className="list-decimal space-y-1 pl-5 text-sm text-muted-foreground">
                    <li>
                        No Jellyfin, em Painel → Plugins → Catálogo, instale o
                        plugin <strong>Webhook</strong> e reinicie o servidor.
                    </li>
                    <li>
                        Abra o plugin Webhook e clique em{' '}
                        <strong>Add Generic Destination</strong>.
                    </li>
                    <li>
                        Cole a URL acima em <strong>Webhook Url</strong>.
                    </li>
                    <li>
                        Marque só <strong>Playback Start</strong> e{' '}
                        <strong>Playback Stop</strong> nos tipos de notificação
                        e os tipos Movies, Episodes e Videos. Deixe o{' '}
                        <strong>User Filter</strong> vazio para contar todos da
                        casa.
                    </li>
                    <li>
                        Marque <strong>Send All Properties</strong>.
                    </li>
                    <li>
                        Em <strong>Headers</strong>, adicione a chave{' '}
                        <code className="font-mono">X-Gamidas-Token</code> com o
                        token como valor e salve.
                    </li>
                </ol>

                <div className="flex flex-wrap gap-2">
                    <Button
                        variant={jellyfin.connected ? 'outline' : 'default'}
                        disabled={issuing}
                        onClick={issue}
                    >
                        {issuing ? (
                            <Spinner />
                        ) : jellyfin.connected ? (
                            <KeyRound />
                        ) : (
                            <Plug />
                        )}
                        {jellyfin.connected
                            ? 'Gerar novo token'
                            : 'Gerar token'}
                    </Button>
                    {jellyfin.connected && (
                        <ConfirmDialog
                            trigger={
                                <Button variant="ghost">
                                    <Unplug />
                                    Desconectar
                                </Button>
                            }
                            title="Desconectar o Jellyfin?"
                            description="O token atual deixa de funcionar. O histórico já registrado continua aqui."
                            confirmLabel="Desconectar"
                            url={watched.jellyfin.destroy.url()}
                        />
                    )}
                </div>
            </CardContent>
        </Card>
    );
}
