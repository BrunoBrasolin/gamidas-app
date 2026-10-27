import { Head } from '@inertiajs/react';
import { Check, Crown, Medal, RotateCcw, Trophy, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { AlternatingChallenge } from '@/components/attention/challenges/alternating';
import { DividedChallenge } from '@/components/attention/challenges/divided';
import { SelectiveChallenge } from '@/components/attention/challenges/selective';
import { SustainedChallenge } from '@/components/attention/challenges/sustained';
import { VoluntaryChallenge } from '@/components/attention/challenges/voluntary';
import {
    OptionButton,
    TimerBar,
    useDeadline,
} from '@/components/attention/game-kit';
import {
    GameButton,
    GameCard,
    GameShell,
} from '@/components/attention/game-shell';
import { ATTENTION_INFO, ATTENTION_ORDER, formatPoints } from '@/lib/attention';
import { HttpError, jsonRequest } from '@/lib/http';
import { cn } from '@/lib/utils';
import game from '@/routes/game';
import type {
    AttentionPlayerState,
    AttentionRankingRow,
    AttentionType,
    AttentionTypeOption,
    ChallengeOutcome,
} from '@/types';

type Props = {
    game: { title: string; code: string; open: boolean };
    types: AttentionTypeOption[];
    scoring: { round_max: number; game_max: number; concept: number };
};

type RoundAnswer = {
    type: AttentionType;
    correct: boolean;
    guess_correct: boolean;
    performance_points: number;
    points: number;
};

type Stage =
    | { name: 'loading' }
    | { name: 'join' }
    | { name: 'closed' }
    | { name: 'intro'; type: AttentionType }
    | { name: 'countdown'; type: AttentionType }
    | { name: 'playing'; type: AttentionType }
    | { name: 'guess'; type: AttentionType; outcome: ChallengeOutcome }
    | {
          name: 'sending';
          type: AttentionType;
          outcome: ChallengeOutcome;
          guess: AttentionType | null;
          error?: string;
      }
    | {
          name: 'feedback';
          type: AttentionType;
          outcome: ChallengeOutcome;
          guess: AttentionType | null;
          round: RoundAnswer;
      }
    | { name: 'finished' };

const GUESS_MS = 15_000;
const GUESS_TIMEOUT = 'timeout';

const CHALLENGES: Record<
    AttentionType,
    (props: { onDone: (outcome: ChallengeOutcome) => void }) => React.ReactNode
> = {
    selective: SelectiveChallenge,
    sustained: SustainedChallenge,
    divided: DividedChallenge,
    alternating: AlternatingChallenge,
    voluntary: VoluntaryChallenge,
};

function storageKey(code: string): string {
    return `attention-game:${code}`;
}

function readToken(code: string): string | null {
    try {
        return window.localStorage.getItem(storageKey(code));
    } catch {
        return null;
    }
}

function writeToken(code: string, token: string | null): void {
    try {
        if (token === null) {
            window.localStorage.removeItem(storageKey(code));
        } else {
            window.localStorage.setItem(storageKey(code), token);
        }
    } catch {
        // Without storage the game still works; it just can't be resumed.
    }
}

function errorMessage(error: unknown): string {
    if (error instanceof HttpError) {
        const body = error.body as {
            message?: string;
            errors?: Record<string, string[]>;
        } | null;
        const first = body?.errors ? Object.values(body.errors)[0]?.[0] : null;

        if (error.status === 429) {
            return 'Muita gente ao mesmo tempo! Espere alguns segundos e tente de novo.';
        }

        if (error.status === 419) {
            return 'A página expirou. Recarregue para continuar.';
        }

        return first ?? body?.message ?? 'Algo deu errado. Tente de novo.';
    }

    return 'Sem conexão. Verifique a internet e tente de novo.';
}

function nextType(answered: AttentionType[]): AttentionType | null {
    return ATTENTION_ORDER.find((type) => !answered.includes(type)) ?? null;
}

export default function GamePlay({ game: room, types, scoring }: Props) {
    const [stage, setStage] = useState<Stage>({ name: 'loading' });
    const [player, setPlayer] = useState<AttentionPlayerState | null>(null);
    const [token, setToken] = useState<string | null>(null);
    const [results, setResults] = useState<
        Partial<Record<AttentionType, RoundAnswer>>
    >({});

    function advance(state: AttentionPlayerState) {
        const type = nextType(state.answered);
        setStage(
            type === null ? { name: 'finished' } : { name: 'intro', type },
        );
    }

    useEffect(() => {
        const saved = readToken(room.code);

        if (saved === null) {
            setStage(room.open ? { name: 'join' } : { name: 'closed' });

            return;
        }

        jsonRequest<{ player: AttentionPlayerState }>(
            'POST',
            game.resume.url(room.code),
            { token: saved },
        )
            .then(({ player: state }) => {
                setToken(saved);
                setPlayer(state);
                const type = nextType(state.answered);

                if (type === null) {
                    setStage({ name: 'finished' });
                } else {
                    setStage(
                        room.open
                            ? { name: 'intro', type }
                            : { name: 'closed' },
                    );
                }
            })
            .catch(() => {
                writeToken(room.code, null);
                setStage(room.open ? { name: 'join' } : { name: 'closed' });
            });
    }, [room.code, room.open]);

    function joined(state: AttentionPlayerState, newToken: string) {
        writeToken(room.code, newToken);
        setToken(newToken);
        setPlayer(state);
        advance(state);
    }

    function send(
        type: AttentionType,
        outcome: ChallengeOutcome,
        guess: AttentionType | null,
    ) {
        setStage({ name: 'sending', type, outcome, guess });

        jsonRequest<{ round: RoundAnswer; player: AttentionPlayerState }>(
            'POST',
            game.rounds.store.url(room.code),
            {
                token,
                type,
                guess,
                correct: outcome.correct,
                accuracy: outcome.accuracy,
                hits: outcome.hits,
                errors: outcome.errors,
                misses: outcome.misses,
                duration_ms: outcome.duration_ms,
                reaction_ms: outcome.reaction_ms,
                details: outcome.details ?? null,
            },
        )
            .then(({ round, player: state }) => {
                setPlayer(state);
                setResults((current) => ({ ...current, [type]: round }));
                setStage({ name: 'feedback', type, outcome, guess, round });
            })
            .catch((error: unknown) => {
                if (
                    error instanceof HttpError &&
                    error.status === 422 &&
                    (error.body as { errors?: Record<string, unknown> })?.errors
                        ?.game
                ) {
                    setStage({ name: 'closed' });

                    return;
                }

                setStage({
                    name: 'sending',
                    type,
                    outcome,
                    guess,
                    error: errorMessage(error),
                });
            });
    }

    const header = player && (
        <span className="flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-sm">
            <span className="max-w-28 truncate font-medium">
                {player.nickname}
            </span>
            <span className="font-mono font-bold text-amber-300 tabular-nums">
                {formatPoints(player.score)}
            </span>
        </span>
    );

    return (
        <GameShell header={header}>
            <Head title={`${room.title} · Jogo da Atenção`} />

            {stage.name === 'loading' && (
                <div className="flex flex-1 items-center justify-center text-white/60">
                    Carregando…
                </div>
            )}

            {stage.name === 'closed' && (
                <Closed code={room.code} playerId={player?.id ?? null} />
            )}

            {stage.name === 'join' && (
                <JoinForm room={room} onJoined={joined} />
            )}

            {stage.name === 'intro' && (
                <Intro
                    type={stage.type}
                    position={ATTENTION_ORDER.indexOf(stage.type) + 1}
                    onStart={() =>
                        setStage({ name: 'countdown', type: stage.type })
                    }
                />
            )}

            {stage.name === 'countdown' && (
                <Countdown
                    onDone={() =>
                        setStage({ name: 'playing', type: stage.type })
                    }
                />
            )}

            {stage.name === 'playing' &&
                (() => {
                    const Challenge = CHALLENGES[stage.type];

                    return (
                        <Challenge
                            key={stage.type}
                            onDone={(outcome) =>
                                setStage({
                                    name: 'guess',
                                    type: stage.type,
                                    outcome,
                                })
                            }
                        />
                    );
                })()}

            {stage.name === 'guess' && (
                <Guess
                    key={stage.type}
                    types={types}
                    concept={scoring.concept}
                    onGuess={(guess) => send(stage.type, stage.outcome, guess)}
                />
            )}

            {stage.name === 'sending' && (
                <div className="flex flex-1 flex-col items-center justify-center gap-4 text-center">
                    {stage.error ? (
                        <>
                            <p className="text-rose-300">{stage.error}</p>
                            <GameButton
                                onClick={() =>
                                    send(stage.type, stage.outcome, stage.guess)
                                }
                            >
                                <RotateCcw className="mr-2 inline size-5" />
                                Tentar de novo
                            </GameButton>
                        </>
                    ) : (
                        <p className="animate-pulse text-white/70">
                            Calculando seus pontos…
                        </p>
                    )}
                </div>
            )}

            {stage.name === 'feedback' && player && (
                <Feedback
                    type={stage.type}
                    outcome={stage.outcome}
                    guess={stage.guess}
                    round={stage.round}
                    total={player.score}
                    last={nextType(player.answered) === null}
                    onNext={() => advance(player)}
                />
            )}

            {stage.name === 'finished' && player && (
                <Finished
                    code={room.code}
                    player={player}
                    results={results}
                    gameMax={scoring.game_max}
                />
            )}
        </GameShell>
    );
}

function JoinForm({
    room,
    onJoined,
}: {
    room: Props['game'];
    onJoined: (player: AttentionPlayerState, token: string) => void;
}) {
    const [nickname, setNickname] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);

    function submit(event: React.FormEvent) {
        event.preventDefault();
        setProcessing(true);
        setError(null);

        jsonRequest<{ player: AttentionPlayerState; token: string }>(
            'POST',
            game.join.url(room.code),
            { nickname: nickname.trim() },
        )
            .then(({ player, token }) => onJoined(player, token))
            .catch((caught: unknown) => {
                setError(errorMessage(caught));
                setProcessing(false);
            });
    }

    return (
        <div className="flex flex-1 flex-col justify-center gap-6">
            <div className="space-y-2 text-center">
                <p className="text-5xl">🧠</p>
                <h1 className="text-3xl font-black">{room.title}</h1>
                <p className="text-white/70">
                    5 desafios rápidos. Em cada um, descubra também qual tipo de
                    atenção você usou!
                </p>
            </div>
            <GameCard>
                <form onSubmit={submit} className="space-y-4">
                    <label className="block space-y-2">
                        <span className="text-sm font-medium text-white/80">
                            Seu apelido
                        </span>
                        <input
                            value={nickname}
                            onChange={(event) =>
                                setNickname(event.target.value)
                            }
                            maxLength={24}
                            autoFocus
                            autoComplete="off"
                            placeholder="Ex.: Ana P."
                            className="w-full rounded-2xl border-2 border-white/20 bg-white/10 px-4 py-4 text-xl font-semibold placeholder:text-white/30 focus:border-fuchsia-400 focus:outline-none"
                        />
                    </label>
                    {error && <p className="text-sm text-rose-300">{error}</p>}
                    <GameButton
                        type="submit"
                        disabled={processing || nickname.trim().length < 2}
                    >
                        {processing ? 'Entrando…' : 'Bora jogar!'}
                    </GameButton>
                </form>
            </GameCard>
            <p className="text-center text-xs text-white/50">
                Sala <span className="font-mono font-bold">{room.code}</span> ·
                só o apelido é registrado.
            </p>
        </div>
    );
}

function Intro({
    type,
    position,
    onStart,
}: {
    type: AttentionType;
    position: number;
    onStart: () => void;
}) {
    const info = ATTENTION_INFO[type];

    return (
        <div className="flex flex-1 flex-col justify-center gap-5">
            <div className="flex justify-center gap-1.5">
                {ATTENTION_ORDER.map((item, index) => (
                    <span
                        key={item}
                        className={cn(
                            'h-1.5 w-10 rounded-full',
                            index < position ? 'bg-fuchsia-400' : 'bg-white/15',
                        )}
                    />
                ))}
            </div>
            <GameCard className="space-y-4 text-center">
                <p className="text-sm font-semibold tracking-widest text-white/60 uppercase">
                    Desafio {position} de {ATTENTION_ORDER.length}
                </p>
                <h2
                    className={cn(
                        'bg-gradient-to-r bg-clip-text text-4xl font-black text-transparent',
                        info.accent,
                    )}
                >
                    {info.challenge}
                </h2>
                <p className="text-lg leading-relaxed">{info.instruction}</p>
            </GameCard>
            <GameButton onClick={onStart}>Estou pronto!</GameButton>
        </div>
    );
}

function Countdown({ onDone }: { onDone: () => void }) {
    const [count, setCount] = useState(3);

    useDeadline(2_100, onDone);

    useEffect(() => {
        const timer = window.setInterval(
            () => setCount((value) => Math.max(1, value - 1)),
            700,
        );

        return () => window.clearInterval(timer);
    }, []);

    return (
        <div className="flex flex-1 items-center justify-center">
            <span
                key={count}
                className="animate-in text-[10rem] leading-none font-black duration-300 zoom-in-50 fade-in"
            >
                {count}
            </span>
        </div>
    );
}

function Guess({
    types,
    concept,
    onGuess,
}: {
    types: AttentionTypeOption[];
    concept: number;
    onGuess: (guess: AttentionType | null) => void;
}) {
    const [picked, setPicked] = useState<
        AttentionType | typeof GUESS_TIMEOUT | null
    >(null);

    function pick(guess: AttentionType | null) {
        if (picked !== null) {
            return;
        }

        setPicked(guess ?? GUESS_TIMEOUT);
        onGuess(guess);
    }

    useDeadline(GUESS_MS, () => pick(null));

    return (
        <div className="flex flex-1 flex-col justify-center gap-4">
            <div className="space-y-2 text-center">
                <p className="text-sm font-semibold tracking-widest text-fuchsia-300 uppercase">
                    Valendo +{concept} pontos
                </p>
                <h2 className="text-2xl font-black">
                    Que tipo de atenção você mais usou nesse desafio?
                </h2>
                <TimerBar durationMs={GUESS_MS} />
            </div>
            <div className="grid gap-2.5">
                {types.map((type) => {
                    const Icon = ATTENTION_INFO[type.value].icon;

                    return (
                        <OptionButton
                            key={type.value}
                            selected={picked === type.value}
                            disabled={picked !== null}
                            onClick={() => pick(type.value)}
                            className="flex items-center gap-3 py-3.5 text-left text-lg"
                        >
                            <Icon className="size-6 shrink-0" aria-hidden />
                            {type.label}
                        </OptionButton>
                    );
                })}
            </div>
        </div>
    );
}

function useCountUp(target: number, durationMs = 700): number {
    const [value, setValue] = useState(0);

    useEffect(() => {
        const start = performance.now();
        let frame = 0;
        const tick = () => {
            const progress = Math.min(
                1,
                (performance.now() - start) / durationMs,
            );
            setValue(Math.round(target * progress));

            if (progress < 1) {
                frame = requestAnimationFrame(tick);
            }
        };
        frame = requestAnimationFrame(tick);

        return () => cancelAnimationFrame(frame);
    }, [target, durationMs]);

    return value;
}

function Feedback({
    type,
    outcome,
    guess,
    round,
    total,
    last,
    onNext,
}: {
    type: AttentionType;
    outcome: ChallengeOutcome;
    guess: AttentionType | null;
    round: RoundAnswer;
    total: number;
    last: boolean;
    onNext: () => void;
}) {
    const info = ATTENTION_INFO[type];
    const Icon = info.icon;
    const points = useCountUp(round.points);

    return (
        <div className="flex flex-1 flex-col justify-center gap-4">
            <div className="text-center">
                <p className="text-sm text-white/60">Você ganhou</p>
                <p className="font-mono text-6xl font-black text-amber-300 tabular-nums">
                    +{points}
                </p>
                <p className="text-sm text-white/60">
                    Total: {formatPoints(total)} pontos
                </p>
            </div>

            <GameCard className="space-y-3">
                <ResultLine ok={round.correct}>{outcome.summary}</ResultLine>
                <ResultLine ok={round.guess_correct}>
                    {round.guess_correct
                        ? `Você identificou certo: ${info.label}!`
                        : guess === null
                          ? `O tempo acabou. Era ${info.label}.`
                          : `Você marcou ${ATTENTION_INFO[guess].label}, mas era ${info.label}.`}
                </ResultLine>
            </GameCard>

            <GameCard className="space-y-2">
                <p className="flex items-center gap-2 font-bold">
                    <span
                        className={cn(
                            'rounded-xl bg-gradient-to-br p-1.5',
                            info.accent,
                        )}
                    >
                        <Icon className="size-5" aria-hidden />
                    </span>
                    {info.label}
                </p>
                <p className="text-sm text-white/80 italic">
                    {info.definition}
                </p>
                <p className="text-sm leading-relaxed">{info.explanation}</p>
            </GameCard>

            <GameButton onClick={onNext}>
                {last ? 'Ver meu resultado' : 'Próximo desafio'}
            </GameButton>
        </div>
    );
}

function ResultLine({
    ok,
    children,
}: {
    ok: boolean;
    children: React.ReactNode;
}) {
    return (
        <p className="flex items-start gap-2.5 text-sm leading-relaxed">
            <span
                className={cn(
                    'mt-0.5 rounded-full p-0.5',
                    ok ? 'bg-emerald-500' : 'bg-rose-500',
                )}
            >
                {ok ? (
                    <Check className="size-3.5" aria-hidden />
                ) : (
                    <X className="size-3.5" aria-hidden />
                )}
            </span>
            <span>{children}</span>
        </p>
    );
}

type RankingResponse = {
    ranking: AttentionRankingRow[];
    players: number;
    finished: number;
    open: boolean;
};

/** The room's ranking, refreshed every few seconds. */
function useRanking(code: string): RankingResponse | null {
    const [data, setData] = useState<RankingResponse | null>(null);

    useEffect(() => {
        const controller = new AbortController();
        const load = () =>
            jsonRequest<RankingResponse>(
                'GET',
                game.ranking.url(code),
                undefined,
                controller.signal,
            )
                .then(setData)
                .catch(() => undefined);

        void load();
        const timer = window.setInterval(load, 4_000);

        return () => {
            controller.abort();
            window.clearInterval(timer);
        };
    }, [code]);

    return data;
}

function Ranking({
    data,
    playerId,
}: {
    data: RankingResponse | null;
    playerId: number | null;
}) {
    if (data === null) {
        return null;
    }

    const own = data.ranking.find((row) => row.id === playerId);
    const top = data.ranking.slice(0, 10);

    return (
        <GameCard className="space-y-3">
            <div className="flex items-center justify-between">
                <p className="flex items-center gap-2 font-bold">
                    <Trophy className="size-5 text-amber-300" aria-hidden />
                    Ranking ao vivo
                </p>
                <p className="text-xs text-white/60">
                    {data.finished}/{data.players} terminaram
                </p>
            </div>
            <ol className="space-y-1.5">
                {top.map((row) => (
                    <RankingItem
                        key={row.id}
                        row={row}
                        own={row.id === playerId}
                    />
                ))}
                {own && own.position > top.length && (
                    <>
                        <li className="text-center text-white/40">⋯</li>
                        <RankingItem row={own} own />
                    </>
                )}
            </ol>
        </GameCard>
    );
}

function RankingItem({ row, own }: { row: AttentionRankingRow; own: boolean }) {
    return (
        <li
            className={cn(
                'flex items-center gap-3 rounded-xl px-3 py-2 text-sm',
                own
                    ? 'bg-fuchsia-500/30 ring-1 ring-fuchsia-300'
                    : 'bg-white/5',
            )}
        >
            <span className="w-6 text-center font-bold">
                {row.position === 1 ? (
                    <Crown className="inline size-4 text-amber-300" />
                ) : row.position <= 3 ? (
                    <Medal className="inline size-4 text-slate-300" />
                ) : (
                    row.position
                )}
            </span>
            <span className="flex-1 truncate">
                {row.nickname}
                {!row.finished && (
                    <span className="ml-1.5 text-xs text-white/50">
                        jogando {row.answered}/{ATTENTION_ORDER.length}
                    </span>
                )}
            </span>
            <span className="font-mono font-bold tabular-nums">
                {formatPoints(row.score)}
            </span>
        </li>
    );
}

function Finished({
    code,
    player,
    results,
    gameMax,
}: {
    code: string;
    player: AttentionPlayerState;
    results: Partial<Record<AttentionType, RoundAnswer>>;
    gameMax: number;
}) {
    const ranking = useRanking(code);
    const score = useCountUp(player.score, 1_200);
    const concepts = Object.values(results).filter(
        (round) => round.guess_correct,
    ).length;
    const known = Object.keys(results).length === ATTENTION_ORDER.length;
    const position = ranking?.ranking.find(
        (row) => row.id === player.id,
    )?.position;

    return (
        <div className="flex flex-1 flex-col gap-4">
            <div className="space-y-1 pt-4 text-center">
                <p className="text-5xl">🎉</p>
                <h2 className="text-2xl font-black">Fim de jogo!</h2>
                <p className="font-mono text-6xl font-black text-amber-300 tabular-nums">
                    {formatPoints(score)}
                </p>
                <p className="text-sm text-white/60">
                    de {formatPoints(gameMax)} pontos possíveis
                    {position ? ` · ${position}º lugar` : ''}
                </p>
            </div>

            <div className="grid grid-cols-2 gap-3">
                <GameCard className="text-center">
                    <p className="text-3xl font-black">
                        {player.correct_rounds}/{ATTENTION_ORDER.length}
                    </p>
                    <p className="text-xs text-white/60">desafios certos</p>
                </GameCard>
                <GameCard className="text-center">
                    <p className="text-3xl font-black">
                        {known ? `${concepts}/${ATTENTION_ORDER.length}` : '—'}
                    </p>
                    <p className="text-xs text-white/60">tipos identificados</p>
                </GameCard>
            </div>

            {known && (
                <GameCard className="space-y-2">
                    {ATTENTION_ORDER.map((type) => {
                        const round = results[type];
                        const info = ATTENTION_INFO[type];
                        const Icon = info.icon;

                        return (
                            <div
                                key={type}
                                className="flex items-center gap-3 text-sm"
                            >
                                <Icon className="size-4 shrink-0" aria-hidden />
                                <span className="flex-1">{info.label}</span>
                                {round?.correct ? (
                                    <Check className="size-4 text-emerald-400" />
                                ) : (
                                    <X className="size-4 text-rose-400" />
                                )}
                                <span className="w-12 text-right font-mono tabular-nums">
                                    {round?.points ?? 0}
                                </span>
                            </div>
                        );
                    })}
                </GameCard>
            )}

            <Ranking data={ranking} playerId={player.id} />
        </div>
    );
}

function Closed({ code, playerId }: { code: string; playerId: number | null }) {
    const ranking = useRanking(code);

    return (
        <div className="flex flex-1 flex-col gap-4">
            <div className="space-y-2 pt-8 text-center">
                <p className="text-5xl">🔒</p>
                <h2 className="text-2xl font-black">Sala encerrada</h2>
                <p className="text-white/70">
                    Esta rodada do jogo já terminou. Obrigado por participar!
                </p>
            </div>
            <Ranking data={ranking} playerId={playerId} />
        </div>
    );
}
