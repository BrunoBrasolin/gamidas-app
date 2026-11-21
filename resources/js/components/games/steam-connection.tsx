import { Form, router } from '@inertiajs/react';
import { ExternalLink, LogIn, RefreshCw, Unplug } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/finance/confirm-dialog';
import { FormField } from '@/components/finance/form-field';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
import games from '@/routes/games';
import type { SteamAccount } from '@/types';

/**
 * Connecting the user's own Steam account: signing in through Steam, or
 * typing a public profile.
 */
export function SteamConnectCard() {
    return (
        <Card className="mx-auto w-full max-w-2xl">
            <CardHeader>
                <CardTitle>Conectar sua conta Steam</CardTitle>
                <CardDescription>
                    Cada pessoa conecta a própria conta, e só ela vê a sua
                    biblioteca. O Gamidas lê jogos, tempo de jogo, nível e
                    conquistas; nunca vê a sua senha.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
                <div className="space-y-2">
                    <Button asChild size="lg">
                        {/* A full page visit: it leaves for steamcommunity.com. */}
                        <a href={games.steam.connect.url()}>
                            <LogIn />
                            Entrar com a Steam
                        </a>
                    </Button>
                    <p className="text-xs text-muted-foreground">
                        Você faz login na página da própria Steam e volta para
                        cá.
                    </p>
                </div>

                <div className="space-y-3 border-t pt-4">
                    <p className="text-sm font-medium">Ou informe o perfil</p>
                    <Form
                        {...games.steam.store.form()}
                        className="flex flex-col gap-3 sm:flex-row sm:items-start"
                    >
                        {({ errors, processing }) => (
                            <>
                                <FormField
                                    id="steam-profile"
                                    label="Link do perfil, SteamID64 ou nome do link personalizado"
                                    error={errors.profile}
                                    className="flex-1"
                                >
                                    <Input
                                        id="steam-profile"
                                        name="profile"
                                        required
                                        maxLength={255}
                                        autoComplete="off"
                                        placeholder="https://steamcommunity.com/id/seu-nome"
                                        aria-invalid={!!errors.profile}
                                    />
                                </FormField>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                    className="sm:mt-[1.375rem]"
                                >
                                    {processing && <Spinner />}
                                    Conectar
                                </Button>
                            </>
                        )}
                    </Form>
                </div>

                <p className="text-xs text-muted-foreground">
                    O perfil precisa mostrar os jogos: na Steam, em Perfil ›
                    Editar perfil › Configurações de privacidade, deixe{' '}
                    <strong>Detalhes do jogo</strong> como{' '}
                    <strong>Público</strong> (e desmarque &quot;Manter meu tempo
                    de jogo privado&quot;).
                </p>
            </CardContent>
        </Card>
    );
}

/**
 * Sync now and disconnect, with when it last synced.
 */
export function SteamAccountCard({ account }: { account: SteamAccount }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Conta Steam</CardTitle>
                <CardDescription>
                    {account.synced_at
                        ? `Biblioteca lida em ${formatDateTime(account.synced_at)}.`
                        : 'A biblioteca ainda não foi lida.'}{' '}
                    O Gamidas atualiza sozinho a cada hora; as conquistas de
                    cada jogo são buscadas depois que ele é jogado.
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-wrap gap-2">
                <Button variant="outline" asChild>
                    <a
                        href={account.profile_url}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        <ExternalLink />
                        Abrir perfil na Steam
                    </a>
                </Button>
                <ConfirmDialog
                    trigger={
                        <Button variant="ghost">
                            <Unplug />
                            Desconectar
                        </Button>
                    }
                    title="Desconectar a Steam?"
                    description="A biblioteca, as conquistas e o histórico de tempo de jogo registrado aqui são apagados; a tier list e a lista para zerar ficam guardadas. Conectar de novo lê a biblioteca outra vez, mas o histórico por dia recomeça do zero."
                    confirmLabel="Desconectar"
                    url={games.steam.destroy.url()}
                />
            </CardContent>
        </Card>
    );
}

/**
 * Reads the profile and library now.
 */
export function SyncButton() {
    const [syncing, setSyncing] = useState(false);

    return (
        <Button
            variant="outline"
            disabled={syncing}
            onClick={() =>
                router.post(
                    games.steam.sync.url(),
                    {},
                    {
                        preserveScroll: true,
                        onStart: () => setSyncing(true),
                        onFinish: () => setSyncing(false),
                    },
                )
            }
        >
            {syncing ? <Spinner /> : <RefreshCw />}
            Sincronizar
        </Button>
    );
}
