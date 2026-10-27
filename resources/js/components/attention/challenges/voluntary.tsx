import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import type { ShapeKind } from '@/components/attention/game-kit';
import {
    ChallengeFrame,
    SHAPE_COLORS,
    Shape,
    TimerBar,
} from '@/components/attention/game-kit';
import { shuffle } from '@/lib/attention';
import { cn } from '@/lib/utils';
import type { ChallengeOutcome } from '@/types';

const TRIAL_MS = 5_000;
const WAIT_MS = 4_000;
const FEEDBACK_MS = 600;

type Choice = { id: string; content: ReactNode; target: boolean };

/** A trial with no target is won by not touching anything. */
type Trial = { instruction: ReactNode; choices: Choice[]; wait?: boolean };

const { blue, red, green, yellow } = SHAPE_COLORS;

function shape(id: string, kind: ShapeKind, color: string, target = false) {
    return {
        id,
        target,
        content: <Shape kind={kind} color={color} className="size-16" />,
    };
}

function word(id: string, text: string, color: string, target = false) {
    return {
        id,
        target,
        content: (
            <span className="text-xl font-black" style={{ color }}>
                {text}
            </span>
        ),
    };
}

function number(id: string, value: number, size: string, target = false) {
    return {
        id,
        target,
        content: <span className={cn('font-black', size)}>{value}</span>,
    };
}

function trials(): Trial[] {
    return [
        {
            instruction: (
                <>
                    Toque no <b>TRIÂNGULO AZUL</b>
                </>
            ),
            choices: shuffle([
                shape('a', 'triangle', blue, true),
                shape('b', 'triangle', red),
                shape('c', 'square', blue),
                shape('d', 'circle', blue),
                shape('e', 'triangle', green),
                word('f', 'AZUL', red),
            ]),
        },
        {
            instruction: (
                <>
                    Toque na palavra escrita com a cor <b>VERDE</b>
                </>
            ),
            choices: shuffle([
                word('a', 'AZUL', green, true),
                word('b', 'VERDE', red),
                word('c', 'VERMELHO', blue),
                word('d', 'AMARELO', yellow),
            ]),
        },
        {
            instruction: (
                <>
                    Toque no quadrado que <b>NÃO</b> é vermelho
                </>
            ),
            choices: shuffle([
                shape('a', 'square', yellow, true),
                shape('b', 'square', red),
                shape('c', 'square', red),
                shape('d', 'square', red),
                shape('e', 'circle', blue),
                shape('f', 'square', red),
            ]),
        },
        {
            instruction: (
                <>
                    <b>NÃO</b> toque em nada. Só espere o tempo acabar.
                </>
            ),
            wait: true,
            choices: [
                {
                    id: 'bait',
                    target: false,
                    content: (
                        <span className="text-2xl font-black text-rose-300">
                            TOQUE AQUI!
                        </span>
                    ),
                },
            ],
        },
        {
            instruction: (
                <>
                    Toque no <b>MAIOR</b> número
                </>
            ),
            choices: shuffle([
                number('a', 9, 'text-lg', true),
                number('b', 3, 'text-7xl'),
                number('c', 5, 'text-5xl'),
                number('d', 2, 'text-6xl'),
            ]),
        },
        {
            instruction: (
                <>
                    Toque na palavra <b>AZUL</b>
                </>
            ),
            choices: shuffle([
                word('a', 'AZUL', red, true),
                word('b', 'VERMELHO', blue),
                word('c', 'VERDE', yellow),
                word('d', 'AMARELO', green),
            ]),
        },
    ];
}

type Result = 'hit' | 'error' | 'miss';

/**
 * Atenção voluntária: follow each written instruction on purpose, against
 * what catches the eye (Stroop-like words, a tempting button).
 */
export function VoluntaryChallenge({
    onDone,
}: {
    onDone: (outcome: ChallengeOutcome) => void;
}) {
    const [list] = useState(trials);
    const [index, setIndex] = useState(0);
    const [feedback, setFeedback] = useState<Result | null>(null);
    const results = useRef<Result[]>([]);
    const times = useRef<number[]>([]);
    const shownAt = useRef(0);
    const done = useRef(false);
    const trial = list[index];
    const limit = trial.wait ? WAIT_MS : TRIAL_MS;

    function finish() {
        if (done.current) {
            return;
        }

        done.current = true;
        const count = (result: Result) =>
            results.current.filter((item) => item === result).length;
        const hits = count('hit');

        onDone({
            correct: hits === list.length,
            accuracy: Math.round((hits / list.length) * 100),
            hits,
            errors: count('error'),
            misses: count('miss'),
            duration_ms: times.current.reduce((sum, ms) => sum + ms, 0),
            reaction_ms: null,
            details: { expected: list.length, answer: hits },
            summary: `Você seguiu ${hits} de ${list.length} instruções corretamente.`,
        });
    }

    function record(result: Result, elapsed: number) {
        if (feedback !== null) {
            return;
        }

        results.current[index] = result;
        times.current[index] = elapsed;
        setFeedback(result);
    }

    const recordRef = useRef(record);
    const finishRef = useRef(finish);

    useEffect(() => {
        recordRef.current = record;
        finishRef.current = finish;
    });

    useEffect(() => {
        if (feedback !== null) {
            const timer = window.setTimeout(() => {
                if (index + 1 >= list.length) {
                    finishRef.current();
                } else {
                    setIndex(index + 1);
                    setFeedback(null);
                }
            }, FEEDBACK_MS);

            return () => window.clearTimeout(timer);
        }

        shownAt.current = performance.now();
        const timer = window.setTimeout(
            () =>
                recordRef.current(list[index].wait ? 'hit' : 'miss', limit),
            limit,
        );

        return () => window.clearTimeout(timer);
    }, [feedback, index, limit, list]);

    function choose(choice: Choice) {
        const elapsed = Math.round(performance.now() - shownAt.current);
        record(choice.target ? 'hit' : 'error', elapsed);
    }

    return (
        <ChallengeFrame
            title={<span className="text-xl">{trial.instruction}</span>}
            hint={`Instrução ${index + 1} de ${list.length}`}
            timer={<TimerBar key={index} durationMs={limit} />}
        >
            <div className="relative flex-1">
                <div
                    className={cn(
                        'mx-auto grid w-full max-w-sm gap-3',
                        trial.choices.length === 1
                            ? 'grid-cols-1'
                            : trial.choices.length === 4
                              ? 'grid-cols-2'
                              : 'grid-cols-3',
                    )}
                >
                    {trial.choices.map((choice) => (
                        <button
                            key={`${index}-${choice.id}`}
                            type="button"
                            disabled={feedback !== null}
                            onClick={() => choose(choice)}
                            className={cn(
                                'flex aspect-square items-center justify-center rounded-2xl bg-white/10 transition select-none active:scale-90',
                                trial.choices.length === 1 &&
                                    'aspect-video animate-pulse bg-rose-500/30',
                            )}
                        >
                            {choice.content}
                        </button>
                    ))}
                </div>
                {feedback !== null && (
                    <div
                        className={cn(
                            'absolute inset-0 flex animate-in items-center justify-center rounded-3xl text-7xl font-black duration-150 zoom-in-75',
                            feedback === 'hit'
                                ? 'bg-emerald-500/80'
                                : 'bg-rose-500/80',
                        )}
                    >
                        {feedback === 'hit' ? '✓' : '✗'}
                    </div>
                )}
            </div>
        </ChallengeFrame>
    );
}
