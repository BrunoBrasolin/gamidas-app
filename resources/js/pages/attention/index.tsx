import { Form, Head, Link } from '@inertiajs/react';
import { Brain, CalendarClock, Plus, Users } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/finance/empty-state';
import { FormField } from '@/components/finance/form-field';
import { PageHeader } from '@/components/finance/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
import { ATTENTION_INFO, ATTENTION_ORDER } from '@/lib/attention';
import { formatDateTime } from '@/lib/format';
import attention from '@/routes/attention';
import type { AttentionGameListItem } from '@/types';

function NewGameDialog() {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>
                    <Plus />
                    Nova sala
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Nova sala do jogo</DialogTitle>
                    <DialogDescription>
                        Cada sala gera um código e um QR Code. Crie uma por
                        apresentação para ter o relatório separado.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...attention.store.form()}
                    onSuccess={() => setOpen(false)}
                    resetOnSuccess
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <FormField
                                id="game-title"
                                label="Nome da sala"
                                error={errors.title}
                            >
                                <Input
                                    id="game-title"
                                    name="title"
                                    required
                                    maxLength={120}
                                    autoFocus
                                    placeholder="Ex.: Apresentação de Atenção — Turma B"
                                />
                            </FormField>
                            <DialogFooter>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    Criar sala
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export default function AttentionIndex({
    games,
}: {
    games: AttentionGameListItem[];
}) {
    return (
        <>
            <Head title="Jogo da Atenção" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Jogo da Atenção"
                    description="Quiz prático para o fim da apresentação: os colegas jogam pelo celular e você acompanha os resultados aqui."
                >
                    <NewGameDialog />
                </PageHeader>

                <Card className="py-4">
                    <CardContent className="grid gap-3 px-4 sm:grid-cols-5">
                        {ATTENTION_ORDER.map((type, index) => {
                            const info = ATTENTION_INFO[type];
                            const Icon = info.icon;

                            return (
                                <div key={type} className="space-y-1">
                                    <p className="flex items-center gap-2 text-sm font-medium">
                                        <span
                                            className={`rounded-md bg-gradient-to-br p-1 text-white ${info.accent}`}
                                        >
                                            <Icon
                                                className="size-4"
                                                aria-hidden
                                            />
                                        </span>
                                        {index + 1}. {info.challenge}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {info.label}
                                    </p>
                                </div>
                            );
                        })}
                    </CardContent>
                </Card>

                {games.length === 0 ? (
                    <EmptyState
                        icon={Brain}
                        title="Nenhuma sala ainda"
                        description="Crie uma sala, projete o QR Code no telão e os colegas entram pelo celular, sem precisar de conta."
                    >
                        <NewGameDialog />
                    </EmptyState>
                ) : (
                    <div className="grid gap-3">
                        {games.map((game) => (
                            <Link
                                key={game.id}
                                href={attention.show(game.id)}
                                prefetch
                            >
                                <Card className="py-4 transition-colors hover:bg-accent/40">
                                    <CardContent className="flex flex-col gap-2 px-4 sm:flex-row sm:items-center sm:justify-between">
                                        <div className="space-y-1">
                                            <p className="flex items-center gap-2 font-medium">
                                                {game.title}
                                                <Badge
                                                    variant={
                                                        game.open
                                                            ? 'default'
                                                            : 'secondary'
                                                    }
                                                >
                                                    {game.open
                                                        ? 'Aberta'
                                                        : 'Encerrada'}
                                                </Badge>
                                            </p>
                                            <p className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
                                                <span className="font-mono font-semibold tracking-wider">
                                                    {game.code}
                                                </span>
                                                {game.created_at && (
                                                    <span className="flex items-center gap-1.5">
                                                        <CalendarClock
                                                            className="size-3.5"
                                                            aria-hidden
                                                        />
                                                        {formatDateTime(
                                                            game.created_at,
                                                        )}
                                                    </span>
                                                )}
                                                {game.created_by && (
                                                    <span>
                                                        por {game.created_by}
                                                    </span>
                                                )}
                                            </p>
                                        </div>
                                        <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
                                            <Users
                                                className="size-4"
                                                aria-hidden
                                            />
                                            {game.players}{' '}
                                            {game.players === 1
                                                ? 'jogador'
                                                : 'jogadores'}{' '}
                                            · {game.finished} concluíram
                                        </p>
                                    </CardContent>
                                </Card>
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

AttentionIndex.layout = {
    breadcrumbs: [{ title: 'Jogo da Atenção', href: attention.index() }],
};
