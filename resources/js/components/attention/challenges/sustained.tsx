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

const LENGTH = 22;
const SHOW_MS = 650;
const GAP_MS = 200;
const ANSWER_MS = 10_000;

type Item = { kind: ShapeKind; color: string };

function sequence(stars: number): Item[] {
    const colors = Object.values(SHAPE_COLORS);
    const color = () => colors[randomInt(0, colors.length - 1)];
    const outlines = randomInt(3, 4);
    const others: ShapeKind[] = ['circle', 'triangle', 'square', 'diamond'];

    return shuffle([
        ...Array.from({ length: stars }, () => ({
            kind: 'star' as const,
            color: color(),
        })),
        ...Array.from({ length: outlines }, () => ({
            kind: 'star-outline' as const,
            color: color(),
        })),
        ...Array.from({ length: LENGTH - stars - outlines }, () => ({
            kind: others[randomInt(0, others.length - 1)],
            color: color(),
        })),
    ]);
}

/**
 * Atenção sustentada: count the filled stars in a long sequence.
 */
export function SustainedChallenge({
    onDone,
}: {
    onDone: (outcome: ChallengeOutcome) => void;
}) {
    const [stars] = useState(() => randomInt(5, 8));
    const [items] = useState(() => sequence(stars));
    const [options] = useState(() => countOptions(stars));
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
        const item = items[step];

        return (
            <ChallengeFrame
                title="Conte as estrelas cheias ★"
                hint="Não vale a estrela vazia ☆"
                timer={
                    <div className="h-1.5 w-full overflow-hidden rounded-full bg-white/15">
                        <div
                            className="h-full bg-amber-400 transition-all"
                            style={{
                                width: `${(step / items.length) * 100}%`,
                            }}
                        />
                    </div>
                }
            >
                <div className="flex flex-1 items-center justify-center">
                    {visible && (
                        <Shape
                            key={step}
                            kind={item.kind}
                            color={item.color}
                            className="size-40 animate-in duration-150 zoom-in-50"
                        />
                    )}
                </div>
            </ChallengeFrame>
        );
    }

    return <CountAnswer stars={stars} options={options} onDone={onDone} />;
}

function CountAnswer({
    stars,
    options,
    onDone,
}: {
    stars: number;
    options: number[];
    onDone: (outcome: ChallengeOutcome) => void;
}) {
    const done = useRef(false);

    function answer(value: number | null, elapsed: number) {
        if (done.current) {
            return;
        }

        done.current = true;
        const correct = value === stars;

        onDone({
            correct,
            accuracy: closeness(stars, value, 25),
            hits: correct ? 1 : 0,
            errors: value !== null && !correct ? 1 : 0,
            misses: value === null ? 1 : 0,
            duration_ms: Math.min(elapsed, ANSWER_MS),
            reaction_ms: null,
            details: {
                expected: stars,
                ...(value === null ? {} : { answer: value }),
            },
            summary:
                value === null
                    ? `O tempo acabou. Eram ${stars} estrelas cheias.`
                    : correct
                      ? `Exato! Eram ${stars} estrelas cheias.`
                      : `Você contou ${value}, mas eram ${stars} estrelas cheias.`,
        });
    }

    const elapsed = useDeadline(ANSWER_MS, () => answer(null, ANSWER_MS));

    return (
        <ChallengeFrame
            title="Quantas estrelas cheias ★ apareceram?"
            timer={<TimerBar durationMs={ANSWER_MS} />}
        >
            <div className="mx-auto grid w-full max-w-sm grid-cols-2 gap-3">
                {options.map((option) => (
                    <OptionButton
                        key={option}
                        onClick={() => answer(option, elapsed())}
                        className="py-6 text-4xl"
                    >
                        {option}
                    </OptionButton>
                ))}
            </div>
        </ChallengeFrame>
    );
}
