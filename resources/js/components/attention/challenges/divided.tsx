import { useEffect, useRef, useState } from 'react';
import type { ShapeKind } from '@/components/attention/game-kit';
import {
    ChallengeFrame,
    OptionButton,
    SHAPE_COLORS,
    Shape,
    TimerBar,
    closeness,
    useDeadline,
} from '@/components/attention/game-kit';
import { countOptions, randomInt, shuffle } from '@/lib/attention';
import type { ChallengeOutcome } from '@/types';

const LENGTH = 14;
const SHOW_MS = 1_100;
const GAP_MS = 250;
const ANSWER_MS = 15_000;
const NUMBER = 7;

type Card = { kind: ShapeKind; color: string; number: number };

/**
 * Circles and sevens are placed independently, so a card may have both,
 * either or neither.
 */
function cards(circles: number, sevens: number): Card[] {
    const colors = Object.values(SHAPE_COLORS);
    const others: ShapeKind[] = ['square', 'triangle', 'diamond'];
    const kinds = shuffle([
        ...Array<ShapeKind>(circles).fill('circle'),
        ...Array.from(
            { length: LENGTH - circles },
            () => others[randomInt(0, others.length - 1)],
        ),
    ]);
    const numbers = shuffle([
        ...Array<number>(sevens).fill(NUMBER),
        ...Array.from({ length: LENGTH - sevens }, () => {
            const value = randomInt(1, 8);

            return value >= NUMBER ? value + 1 : value;
        }),
    ]);

    return kinds.map((kind, index) => ({
        kind,
        color: colors[randomInt(0, colors.length - 1)],
        number: numbers[index],
    }));
}

/**
 * Atenção dividida: count circles and sevens at the same time.
 */
export function DividedChallenge({
    onDone,
}: {
    onDone: (outcome: ChallengeOutcome) => void;
}) {
    const [circles] = useState(() => randomInt(3, 6));
    const [sevens] = useState(() => randomInt(2, 5));
    const [items] = useState(() => cards(circles, sevens));
    const [step, setStep] = useState(0);
    const [visible, setVisible] = useState(true);
    const watching = step < items.length;

    useEffect(() => {
        if (!watching) {
            return;
        }

        const hide = window.setTimeout(() => setVisible(false), SHOW_MS);
        const next = window.setTimeout(() => {
            setStep(step + 1);
            setVisible(true);
        }, SHOW_MS + GAP_MS);

        return () => {
            window.clearTimeout(hide);
            window.clearTimeout(next);
        };
    }, [step, watching]);

    if (watching) {
        const card = items[step];

        return (
            <ChallengeFrame
                title="Conte os ● círculos E os números 7"
                hint="As duas contagens ao mesmo tempo!"
                timer={
                    <div className="h-1.5 w-full overflow-hidden rounded-full bg-white/15">
                        <div
                            className="h-full bg-fuchsia-400 transition-all"
                            style={{
                                width: `${(step / items.length) * 100}%`,
                            }}
                        />
                    </div>
                }
            >
                <div className="flex flex-1 items-center justify-center">
                    {visible && (
                        <div
                            key={step}
                            className="flex w-full max-w-sm animate-in items-center justify-around rounded-3xl bg-white/10 py-10 duration-150 zoom-in-75"
                        >
                            <Shape
                                kind={card.kind}
                                color={card.color}
                                className="size-28"
                            />
                            <span className="font-mono text-8xl font-black tabular-nums">
                                {card.number}
                            </span>
                        </div>
                    )}
                </div>
            </ChallengeFrame>
        );
    }

    return <DoubleAnswer circles={circles} sevens={sevens} onDone={onDone} />;
}

function DoubleAnswer({
    circles,
    sevens,
    onDone,
}: {
    circles: number;
    sevens: number;
    onDone: (outcome: ChallengeOutcome) => void;
}) {
    const [circleOptions] = useState(() => countOptions(circles));
    const [sevenOptions] = useState(() => countOptions(sevens));
    const [circleAnswer, setCircleAnswer] = useState<number | null>(null);
    const [sevenAnswer, setSevenAnswer] = useState<number | null>(null);
    const done = useRef(false);

    function finish(a: number | null, b: number | null, elapsed: number) {
        if (done.current) {
            return;
        }

        done.current = true;
        const rightA = a === circles;
        const rightB = b === sevens;
        const answered = (a === null ? 0 : 1) + (b === null ? 0 : 1);
        const hits = (rightA ? 1 : 0) + (rightB ? 1 : 0);

        onDone({
            correct: rightA && rightB,
            accuracy: Math.round(
                (closeness(circles, a, 34) + closeness(sevens, b, 34)) / 2,
            ),
            hits,
            errors: answered - hits,
            misses: 2 - answered,
            duration_ms: Math.min(elapsed, ANSWER_MS),
            reaction_ms: null,
            details: {
                expected: circles,
                expected_secondary: sevens,
                ...(a === null ? {} : { answer: a }),
                ...(b === null ? {} : { answer_secondary: b }),
            },
            summary: `Eram ${circles} círculos (você: ${a ?? '—'}) e ${sevens} números 7 (você: ${b ?? '—'}).`,
        });
    }

    const elapsed = useDeadline(ANSWER_MS, () =>
        finish(circleAnswer, sevenAnswer, ANSWER_MS),
    );

    function pickCircle(value: number) {
        setCircleAnswer(value);

        if (sevenAnswer !== null) {
            finish(value, sevenAnswer, elapsed());
        }
    }

    function pickSeven(value: number) {
        setSevenAnswer(value);

        if (circleAnswer !== null) {
            finish(circleAnswer, value, elapsed());
        }
    }

    return (
        <ChallengeFrame
            title="Responda as duas perguntas"
            timer={<TimerBar durationMs={ANSWER_MS} />}
        >
            <div className="mx-auto w-full max-w-sm space-y-5">
                <div className="space-y-2">
                    <p className="flex items-center gap-2 font-medium">
                        <Shape kind="circle" className="size-5" />
                        Quantos círculos?
                    </p>
                    <div className="grid grid-cols-4 gap-2">
                        {circleOptions.map((option) => (
                            <OptionButton
                                key={option}
                                selected={circleAnswer === option}
                                onClick={() => pickCircle(option)}
                            >
                                {option}
                            </OptionButton>
                        ))}
                    </div>
                </div>
                <div className="space-y-2">
                    <p className="font-medium">
                        Quantas vezes apareceu o número {NUMBER}?
                    </p>
                    <div className="grid grid-cols-4 gap-2">
                        {sevenOptions.map((option) => (
                            <OptionButton
                                key={option}
                                selected={sevenAnswer === option}
                                onClick={() => pickSeven(option)}
                            >
                                {option}
                            </OptionButton>
                        ))}
                    </div>
                </div>
            </div>
        </ChallengeFrame>
    );
}
