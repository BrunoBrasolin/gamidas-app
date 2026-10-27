import { Head, router, usePoll } from '@inertiajs/react';
import {
    Brain,
    Check,
    ChevronDown,
    Clock,
    Copy,
    Download,
    Gauge,
    Lightbulb,
    Lock,
    LockOpen,
    MonitorPlay,
    Target,
    Trash2,
    Trophy,
    Users,
    X,
    Zap,
} from 'lucide-react';
import { Fragment, useState } from 'react';
import { ConfirmDialog } from '@/components/finance/confirm-dialog';
import { EmptyState } from '@/components/finance/empty-state';
import { PageHeader } from '@/components/finance/page-header';
import { StatCard } from '@/components/finance/stat-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useClipboard } from '@/hooks/use-clipboard';
import {
    ATTENTION_INFO,
    ATTENTION_ORDER,
    formatElapsed,
    formatPoints,
    formatRate,
    formatSeconds,
} from '@/lib/attention';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import attention from '@/routes/attention';
import type {
    AttentionGame,
    AttentionPlayerReport,
    AttentionRoundResult,
    AttentionSummary,
    AttentionType,
    AttentionTypeStats,
} from '@/types';

type Props = {
    game: AttentionGame;
    summary: AttentionSummary;
    types: AttentionTypeStats[];
    players: AttentionPlayerReport[];
};

function RateBar({
    percent,
    className,
    label,
}: {
    percent: number | null;
    className: string;
    label: string;
}) {
    return (
        <div className="flex items-center gap-2">
            <div
                role="progressbar"
                aria-label={label}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={percent ?? 0}
                className="h-2 flex-1 overflow-hidden rounded-full bg-muted"
            >
                <div
                    className={cn('h-full rounded-full', className)}
                    style={{ width: `${percent ?? 0}%` }}
                />
            </div>
            <span className="w-10 text-right text-sm tabular-nums">
                {formatRate(percent)}
            </span>
        </div>
    );
}

function TypeName({ type }: { type: AttentionType }) {
    const info = ATTENTION_INFO[type];
    const Icon = info.icon;

    return (
        <span className="flex items-center gap-2">
            <span
                className={`rounded-md bg-gradient-to-br p-1 text-white ${info.accent}`}
            >
                <Icon className="size-3.5" aria-hidden />
            </span>
            <span>
                <span className="block font-medium">{info.label}</span>
                <span className="block text-xs text-muted-foreground">
                    {info.challenge}
                </span>
            </span>
        </span>
    );
}

function TypesCard({ types }: { types: AttentionTypeStats[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Desempenho por tipo de atenção</CardTitle>
                <CardDescription>
                    “Fez o desafio” mede a execução prática; “Reconheceu o tipo”
                    mede se a pessoa identificou qual atenção estava usando — o
                    objetivo do quiz.
                </CardDescription>
            </CardHeader>
            <CardContent className="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Tipo</TableHead>
                            <TableHead className="min-w-36">
                                Fez o desafio
                            </TableHead>
                            <TableHead className="min-w-36">
                                Reconheceu o tipo
                            </TableHead>
                            <TableHead className="text-right">
                                Precisão
                            </TableHead>
                            <TableHead className="text-right">Tempo</TableHead>
                            <TableHead className="text-right">
                                Erros / omissões
                            </TableHead>
                            <TableHead className="text-right">Pontos</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {types.map((row) => (
                            <TableRow key={row.type}>
                                <TableCell>
                                    <TypeName type={row.type} />
                                </TableCell>
                                <TableCell>
                                    <RateBar
                                        percent={row.success_rate}
                                        className={ATTENTION_INFO[row.type].bar}
                                        label={`Fez o desafio de ${row.label}`}
                                    />
                                </TableCell>
                                <TableCell>
                                    <RateBar
                                        percent={row.concept_rate}
                                        className="bg-primary"
                                        label={`Reconheceu ${row.label}`}
                                    />
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {formatRate(row.average_accuracy)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {row.type === 'alternating'
                                        ? row.average_reaction_ms === null
                                            ? '—'
                                            : `${row.average_reaction_ms} ms`
                                        : formatSeconds(
                                              row.average_duration_ms,
                                          )}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {row.average_errors === null
                                        ? '—'
                                        : `${row.average_errors.toLocaleString('pt-BR')} / ${(row.average_misses ?? 0).toLocaleString('pt-BR')}`}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {formatPoints(row.average_points)}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
                <p className="mt-3 text-xs text-muted-foreground">
                    Tempo: médio para concluir ou responder; na atenção
                    alternada, tempo médio de reação aos alvos. Erros e omissões
                    são médias por jogador.
                </p>
            </CardContent>
        </Card>
    );
}

/**
 * Which kind players named for each challenge: the diagonal is right, the
 * rest shows which concepts get mixed up.
 */
function ConfusionCard({ types }: { types: AttentionTypeStats[] }) {
    const columns: (AttentionType | 'none')[] = [...ATTENTION_ORDER, 'none'];

    return (
        <Card>
            <CardHeader>
                <CardTitle>O que cada desafio foi chamado</CardTitle>
                <CardDescription>
                    Linhas: o tipo certo do desafio. Colunas: o que os jogadores
                    responderam. Fora da diagonal estão as confusões entre os
                    conceitos.
                </CardDescription>
            </CardHeader>
            <CardContent className="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Desafio</TableHead>
                            {columns.map((column) => (
                                <TableHead
                                    key={column}
                                    className="text-center text-xs"
                                >
                                    {column === 'none'
                                        ? 'Sem resposta'
                                        : ATTENTION_INFO[column].label.replace(
                                              'Atenção ',
                                              '',
                                          )}
                                </TableHead>
                            ))}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {types.map((row) => (
                            <TableRow key={row.type}>
                                <TableCell className="font-medium">
                                    {row.label.replace('Atenção ', '')}
                                </TableCell>
                                {columns.map((column) => {
                                    const count = row.guesses[column];
                                    const share =
                                        row.rounds === 0
                                            ? 0
                                            : count / row.rounds;
                                    const right = column === row.type;

                                    return (
                                        <TableCell
                                            key={column}
                                            className="text-center tabular-nums"
                                        >
                                            <span
                                                className={cn(
                                                    'inline-flex min-w-9 justify-center rounded-md px-2 py-1',
                                                    count === 0 &&
                                                        'text-muted-foreground',
                                                    count > 0 &&
                                                        right &&
                                                        'bg-emerald-500/15 font-semibold text-emerald-700 dark:text-emerald-300',
                                                    count > 0 &&
                                                        !right &&
                                                        (share >= 0.25
                                                            ? 'bg-rose-500/20 font-semibold text-rose-700 dark:text-rose-300'
                                                            : 'bg-amber-500/10 text-amber-700 dark:text-amber-300'),
                                                )}
                                            >
                                                {count}
                                            </span>
                                        </TableCell>
                                    );
                                })}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    );
}

function roundDetail(round: AttentionRoundResult): string {
    const details = round.details ?? {};

    switch (round.type) {
        case 'selective':
            return `${round.hits}/${details.expected ?? 5} Q encontrados, ${round.errors} toques errados`;
        case 'sustained':
            return `Contou ${details.answer ?? '—'} (eram ${details.expected ?? '—'})`;
        case 'divided':
            return `Círculos ${details.answer ?? '—'}/${details.expected ?? '—'} · setes ${details.answer_secondary ?? '—'}/${details.expected_secondary ?? '—'}`;
        case 'alternating':
            return `${round.hits} alvos, ${round.misses} omissões, ${round.errors} toques errados (${details.perseverations ?? 0} perseverações)`;
        case 'voluntary':
            return `${round.hits}/${details.expected ?? 6} instruções seguidas`;
    }
}

function PlayerRounds({ player }: { player: AttentionPlayerReport }) {
    return (
        <div className="grid gap-2 py-2 sm:grid-cols-2 lg:grid-cols-5">
            {ATTENTION_ORDER.map((type) => {
                const round = player.rounds.find((item) => item.type === type);
                const info = ATTENTION_INFO[type];

                return (
                    <div
                        key={type}
                        className="space-y-1 rounded-lg border bg-background p-3 text-xs"
                    >
                        <p className="font-medium">{info.label}</p>
                        {round ? (
                            <>
                                <p className="flex items-center gap-1">
                                    {round.correct ? (
                                        <Check className="size-3.5 text-emerald-600" />
                                    ) : (
                                        <X className="size-3.5 text-rose-600" />
                                    )}
                                    {roundDetail(round)}
                                </p>
                                <p className="text-muted-foreground">
                                    Precisão {round.accuracy}% ·{' '}
                                    {round.type === 'alternating' &&
                                    round.reaction_ms !== null
                                        ? `reação ${round.reaction_ms} ms`
                                        : formatSeconds(round.duration_ms)}
                                </p>
                                <p
                                    className={cn(
                                        round.guess_correct
                                            ? 'text-emerald-700 dark:text-emerald-400'
                                            : 'text-rose-700 dark:text-rose-400',
                                    )}
                                >
                                    Respondeu:{' '}
                                    {round.guess
                                        ? ATTENTION_INFO[round.guess].label
                                        : 'sem resposta'}
                                </p>
                                <p className="font-semibold">
                                    {round.points} pontos
                                </p>
                            </>
                        ) : (
                            <p className="text-muted-foreground">
                                Ainda não jogou
                            </p>
                        )}
                    </div>
                );
            })}
        </div>
    );
}

function PlayersCard({ players }: { players: AttentionPlayerReport[] }) {
    const [expanded, setExpanded] = useState<number | null>(null);

    return (
        <Card>
            <CardHeader>
                <CardTitle>Jogadores</CardTitle>
                <CardDescription>
                    Ordenados pela pontuação (empate: quem terminou mais
                    rápido). Toque numa linha para ver cada desafio.
                </CardDescription>
            </CardHeader>
            <CardContent className="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-10">#</TableHead>
                            <TableHead>Apelido</TableHead>
                            <TableHead className="text-right">Pontos</TableHead>
                            <TableHead className="text-right">
                                Desafios certos
                            </TableHead>
                            <TableHead className="text-right">
                                Tipos reconhecidos
                            </TableHead>
                            <TableHead className="text-right">Erros</TableHead>
                            <TableHead className="text-right">
                                Omissões
                            </TableHead>
                            <TableHead className="text-right">
                                Tempo total
                            </TableHead>
                            <TableHead />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {players.map((player) => {
                            const open = expanded === player.id;

                            return (
                                <Fragment key={player.id}>
                                    <TableRow
                                        className="cursor-pointer"
                                        onClick={() =>
                                            setExpanded(open ? null : player.id)
                                        }
                                    >
                                        <TableCell className="font-semibold">
                                            {player.position}
                                        </TableCell>
                                        <TableCell>
                                            <span className="flex items-center gap-2">
                                                {player.nickname}
                                                {!player.finished && (
                                                    <Badge variant="outline">
                                                        jogando{' '}
                                                        {player.answered}/
                                                        {ATTENTION_ORDER.length}
                                                    </Badge>
                                                )}
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-right font-semibold tabular-nums">
                                            {formatPoints(player.score)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {player.correct_rounds}/
                                            {ATTENTION_ORDER.length}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {player.concept_hits}/
                                            {ATTENTION_ORDER.length}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {player.errors}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {player.misses}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatElapsed(
                                                player.elapsed_seconds,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <ChevronDown
                                                className={cn(
                                                    'size-4 transition-transform',
                                                    open && 'rotate-180',
                                                )}
                                                aria-hidden
                                            />
                                        </TableCell>
                                    </TableRow>
                                    {open && (
                                        <TableRow className="hover:bg-transparent">
                                            <TableCell
                                                colSpan={9}
                                                className="bg-muted/40 whitespace-normal"
                                            >
                                                <PlayerRounds player={player} />
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </Fragment>
                            );
                        })}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    );
}

/** Follows the players live; rendered only while the room is open. */
function LiveUpdates() {
    usePoll(5_000, { only: ['game', 'summary', 'types', 'players'] });

    return null;
}

export default function AttentionReport({
    game,
    summary,
    types,
    players,
}: Props) {
    const [copied, copy] = useClipboard();

    const description = [
        `Código ${game.code}`,
        game.created_at && `criada em ${formatDateTime(game.created_at)}`,
        game.created_by && `por ${game.created_by}`,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <>
            <Head title={`${game.title} · Jogo da Atenção`} />
            {game.open && <LiveUpdates />}
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader title={game.title} description={description}>
                    <Badge variant={game.open ? 'default' : 'secondary'}>
                        {game.open
                            ? 'Aberta · atualizando ao vivo'
                            : 'Encerrada'}
                    </Badge>
                    <Button asChild>
                        <a
                            href={attention.present.url(game.id)}
                            target="_blank"
                            rel="noopener"
                        >
                            <MonitorPlay />
                            Telão
                        </a>
                    </Button>
                    <Button variant="outline" onClick={() => copy(game.url)}>
                        {copied === game.url ? <Check /> : <Copy />}
                        {copied === game.url ? 'Copiado!' : 'Copiar link'}
                    </Button>
                    <Button variant="outline" asChild>
                        <a href={attention.export.url(game.id)}>
                            <Download />
                            Planilha
                        </a>
                    </Button>
                    {game.open ? (
                        <ConfirmDialog
                            trigger={
                                <Button variant="outline">
                                    <Lock />
                                    Encerrar
                                </Button>
                            }
                            title="Encerrar a sala?"
                            description="Ninguém mais consegue entrar nem responder. Os resultados continuam aqui e você pode reabrir depois."
                            confirmLabel="Encerrar"
                            destructive={false}
                            method="post"
                            url={attention.close.url(game.id)}
                        />
                    ) : (
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.delete(attention.reopen.url(game.id), {
                                    preserveScroll: true,
                                })
                            }
                        >
                            <LockOpen />
                            Reabrir
                        </Button>
                    )}
                    <ConfirmDialog
                        trigger={
                            <Button variant="ghost" size="icon">
                                <Trash2 />
                                <span className="sr-only">Excluir sala</span>
                            </Button>
                        }
                        title="Excluir a sala?"
                        description="A sala, os jogadores e todos os resultados serão apagados. Isso não pode ser desfeito."
                        confirmLabel="Excluir"
                        url={attention.destroy.url(game.id)}
                    />
                </PageHeader>

                <Card className="py-4">
                    <CardContent className="flex flex-col gap-1 px-4 text-sm sm:flex-row sm:items-center sm:gap-3">
                        <span className="text-muted-foreground">
                            Link para os jogadores:
                        </span>
                        <a
                            href={game.url}
                            target="_blank"
                            rel="noopener"
                            className="font-mono font-medium break-all underline-offset-4 hover:underline"
                        >
                            {game.url}
                        </a>
                    </CardContent>
                </Card>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    <StatCard
                        title="Participantes"
                        value={summary.players}
                        hint={`${summary.finished} concluíram · ${summary.playing} jogando`}
                        icon={Users}
                    />
                    <StatCard
                        title="Pontuação média"
                        value={formatPoints(summary.average_score)}
                        hint={`Melhor: ${formatPoints(summary.best_score)} de ${formatPoints(summary.max_score)}`}
                        icon={Trophy}
                    />
                    <StatCard
                        title="Desafios feitos certo"
                        value={formatRate(summary.success_rate)}
                        hint={`Precisão média ${formatRate(summary.average_accuracy)}`}
                        icon={Target}
                    />
                    <StatCard
                        title="Tipos reconhecidos"
                        value={formatRate(summary.concept_rate)}
                        hint="Acertou qual atenção usou"
                        icon={Lightbulb}
                    />
                    <StatCard
                        title="Tempo médio de jogo"
                        value={formatElapsed(summary.average_elapsed_seconds)}
                        hint="De entrar até o último desafio"
                        icon={Clock}
                    />
                    <StatCard
                        title="Reação média"
                        value={
                            summary.average_reaction_ms === null
                                ? '—'
                                : `${summary.average_reaction_ms} ms`
                        }
                        hint="No “Mudou a regra!”"
                        icon={Zap}
                    />
                </div>

                {summary.players === 0 ? (
                    <EmptyState
                        icon={Brain}
                        title="Ninguém entrou ainda"
                        description="Abra o telão para mostrar o QR Code. Esta página se atualiza sozinha enquanto a sala estiver aberta."
                    />
                ) : (
                    <>
                        <TypesCard types={types} />
                        <ConfusionCard types={types} />
                        <PlayersCard players={players} />
                    </>
                )}

                <p className="flex items-center gap-2 text-xs text-muted-foreground">
                    <Gauge className="size-3.5" aria-hidden />
                    Demonstração didática dos conceitos, não um teste
                    psicológico validado. Só o apelido de cada jogador é
                    registrado.
                </p>
            </div>
        </>
    );
}

AttentionReport.layout = {
    breadcrumbs: [
        { title: 'Jogo da Atenção', href: attention.index() },
        { title: 'Relatório', href: attention.index() },
    ],
};
