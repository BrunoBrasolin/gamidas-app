import { Head, Link, usePoll } from '@inertiajs/react';
import { ArrowLeft, Brain, Crown, Maximize, Medal } from 'lucide-react';
import {
    ATTENTION_INFO,
    ATTENTION_ORDER,
    formatPoints,
    formatRate,
} from '@/lib/attention';
import { cn } from '@/lib/utils';
import attention from '@/routes/attention';
import type {
    AttentionGame,
    AttentionRankingRow,
    AttentionSummary,
    AttentionTypeStats,
} from '@/types';

/**
 * Projector screen: the QR code to join, live counters, the ranking and how
 * well each kind of attention is being recognized.
 */
export default function AttentionPresent({
    game,
    qr,
    summary,
    types,
    ranking,
}: {
    game: AttentionGame;
    qr: string;
    summary: AttentionSummary;
    types: AttentionTypeStats[];
    ranking: AttentionRankingRow[];
}) {
    usePoll(3_000, { only: ['game', 'summary', 'types', 'ranking'] });

    function fullscreen() {
        void document.documentElement.requestFullscreen?.().catch(() => {
            // Fullscreen may be refused (iframe, old browser); the page still works.
        });
    }

    const joinUrl = game.url.replace(/^https?:\/\//, '');

    return (
        <div className="min-h-svh bg-gradient-to-br from-indigo-950 via-slate-900 to-slate-950 p-6 text-white lg:p-10">
            <Head title={`Telão · ${game.title}`} />
            <div className="mx-auto flex max-w-7xl flex-col gap-6">
                <header className="flex items-center justify-between gap-4">
                    <div className="flex items-center gap-3">
                        <Brain
                            className="size-9 text-fuchsia-300"
                            aria-hidden
                        />
                        <div>
                            <p className="text-sm tracking-widest text-white/60 uppercase">
                                Jogo da Atenção
                            </p>
                            <h1 className="text-2xl font-black lg:text-3xl">
                                {game.title}
                            </h1>
                        </div>
                    </div>
                    <div className="flex items-center gap-2 text-sm text-white/60">
                        <Link
                            href={attention.show(game.id)}
                            className="flex items-center gap-1 rounded-lg px-3 py-2 hover:bg-white/10"
                        >
                            <ArrowLeft className="size-4" />
                            Relatório
                        </Link>
                        <button
                            type="button"
                            onClick={fullscreen}
                            className="flex items-center gap-1 rounded-lg px-3 py-2 hover:bg-white/10"
                        >
                            <Maximize className="size-4" />
                            Tela cheia
                        </button>
                    </div>
                </header>

                <div className="grid gap-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
                    <section className="flex flex-col items-center gap-5 rounded-3xl bg-white/5 p-6 text-center">
                        {game.open ? (
                            <>
                                <p className="text-xl font-semibold">
                                    Aponte a câmera do celular
                                </p>
                                <div
                                    className="w-full max-w-sm rounded-3xl bg-white p-4 [&_svg]:h-auto [&_svg]:w-full"
                                    dangerouslySetInnerHTML={{ __html: qr }}
                                />
                                <div className="space-y-1">
                                    <p className="text-white/60">
                                        ou acesse{' '}
                                        <span className="font-mono font-semibold text-white">
                                            {joinUrl}
                                        </span>
                                    </p>
                                    <p className="text-white/60">
                                        código da sala
                                    </p>
                                    <p className="font-mono text-6xl font-black tracking-[0.25em] text-amber-300">
                                        {game.code}
                                    </p>
                                </div>
                            </>
                        ) : (
                            <div className="flex flex-1 flex-col items-center justify-center gap-3 py-16">
                                <p className="text-6xl">🔒</p>
                                <p className="text-3xl font-black">
                                    Sala encerrada
                                </p>
                                <p className="text-white/60">
                                    Obrigado por participar!
                                </p>
                            </div>
                        )}
                    </section>

                    <section className="flex flex-col gap-6">
                        <div className="grid grid-cols-3 gap-4">
                            <Counter label="entraram" value={summary.players} />
                            <Counter label="jogando" value={summary.playing} />
                            <Counter
                                label="terminaram"
                                value={summary.finished}
                            />
                        </div>

                        <div className="rounded-3xl bg-white/5 p-6">
                            <p className="mb-4 text-lg font-bold">
                                🏆 Ranking ao vivo
                            </p>
                            {ranking.length === 0 ? (
                                <p className="py-8 text-center text-white/50">
                                    Esperando os primeiros jogadores…
                                </p>
                            ) : (
                                <ol className="grid gap-2 xl:grid-cols-2">
                                    {ranking.map((row) => (
                                        <li
                                            key={row.id}
                                            className={cn(
                                                'flex items-center gap-3 rounded-2xl px-4 py-2.5 text-lg',
                                                row.position === 1
                                                    ? 'bg-amber-400/20 ring-1 ring-amber-300'
                                                    : 'bg-white/5',
                                            )}
                                        >
                                            <span className="w-7 text-center font-black">
                                                {row.position === 1 ? (
                                                    <Crown className="inline size-5 text-amber-300" />
                                                ) : row.position <= 3 ? (
                                                    <Medal className="inline size-5 text-slate-300" />
                                                ) : (
                                                    row.position
                                                )}
                                            </span>
                                            <span className="flex-1 truncate font-semibold">
                                                {row.nickname}
                                            </span>
                                            {!row.finished && (
                                                <span className="text-xs text-white/50">
                                                    {row.answered}/
                                                    {ATTENTION_ORDER.length}
                                                </span>
                                            )}
                                            <span className="font-mono font-bold text-amber-200 tabular-nums">
                                                {formatPoints(row.score)}
                                            </span>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </div>

                        <div className="rounded-3xl bg-white/5 p-6">
                            <p className="mb-4 text-lg font-bold">
                                💡 Quem reconheceu cada tipo de atenção
                            </p>
                            <div className="grid gap-3">
                                {types.map((row) => {
                                    const info = ATTENTION_INFO[row.type];

                                    return (
                                        <div
                                            key={row.type}
                                            className="flex items-center gap-3"
                                        >
                                            <span className="w-44 shrink-0 text-sm font-medium">
                                                {info.label}
                                            </span>
                                            <div className="h-4 flex-1 overflow-hidden rounded-full bg-white/10">
                                                <div
                                                    className={cn(
                                                        'h-full rounded-full transition-all duration-700',
                                                        info.bar,
                                                    )}
                                                    style={{
                                                        width: `${row.concept_rate ?? 0}%`,
                                                    }}
                                                />
                                            </div>
                                            <span className="w-12 text-right font-mono font-bold tabular-nums">
                                                {formatRate(row.concept_rate)}
                                            </span>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    );
}

function Counter({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-3xl bg-white/5 p-5 text-center">
            <p className="font-mono text-5xl font-black tabular-nums">
                {value}
            </p>
            <p className="text-sm tracking-wide text-white/60 uppercase">
                {label}
            </p>
        </div>
    );
}
