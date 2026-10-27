import { useRef, useState } from 'react';
import {
    ChallengeFrame,
    TimerBar,
    plural,
    useDeadline,
    useSecondsLeft,
} from '@/components/attention/game-kit';
import { shuffle } from '@/lib/attention';
import { cn } from '@/lib/utils';
import type { ChallengeOutcome } from '@/types';

const TIME_MS = 20_000;
const CELLS = 48;
const TARGETS = 5;

/**
 * Atenção seletiva: tap every Q hidden among O's.
 */
export function SelectiveChallenge({
    onDone,
}: {
    onDone: (outcome: ChallengeOutcome) => void;
}) {
    const [letters] = useState(() =>
        shuffle([
            ...Array<string>(TARGETS).fill('Q'),
            ...Array<string>(CELLS - TARGETS).fill('O'),
        ]),
    );
    const [found, setFound] = useState<Set<number>>(() => new Set());
    const [wrong, setWrong] = useState<Set<number>>(() => new Set());
    // Counted in refs too, so taps faster than a render are never lost.
    const foundRef = useRef(new Set<number>());
    const errorsRef = useRef(0);
    const done = useRef(false);

    function finish(hits: number, errorCount: number, elapsed: number) {
        if (done.current) {
            return;
        }

        done.current = true;
        const accuracy = Math.round(
            (Math.max(0, hits - errorCount) / TARGETS) * 100,
        );

        onDone({
            correct: hits === TARGETS && errorCount === 0,
            accuracy,
            hits,
            errors: errorCount,
            misses: TARGETS - hits,
            duration_ms: Math.min(elapsed, TIME_MS),
            reaction_ms: null,
            details: { expected: TARGETS, answer: hits },
            summary:
                `Você encontrou ${hits} de ${TARGETS} letras Q` +
                (errorCount > 0
                    ? ` e tocou ${plural(errorCount, 'vez', 'vezes')} num O.`
                    : ' sem nenhum toque errado.'),
        });
    }

    const elapsed = useDeadline(TIME_MS, () =>
        finish(foundRef.current.size, errorsRef.current, TIME_MS),
    );
    const left = useSecondsLeft(TIME_MS);

    function tap(index: number) {
        if (done.current || foundRef.current.has(index)) {
            return;
        }

        if (letters[index] === 'Q') {
            foundRef.current.add(index);
            setFound(new Set(foundRef.current));

            if (foundRef.current.size === TARGETS) {
                finish(TARGETS, errorsRef.current, elapsed());
            }

            return;
        }

        errorsRef.current++;
        setWrong((current) => new Set(current).add(index));
        window.setTimeout(() => {
            setWrong((current) => {
                const next = new Set(current);
                next.delete(index);

                return next;
            });
        }, 350);
    }

    return (
        <ChallengeFrame
            title={`Ache as ${TARGETS} letras Q`}
            hint={`${found.size}/${TARGETS} encontradas · ${left}s`}
            timer={<TimerBar durationMs={TIME_MS} />}
        >
            <div className="mx-auto grid w-full max-w-sm grid-cols-6 gap-1.5">
                {letters.map((letter, index) => (
                    <button
                        key={index}
                        type="button"
                        onClick={() => tap(index)}
                        className={cn(
                            'aspect-square rounded-lg font-mono text-2xl font-bold transition select-none active:scale-90',
                            found.has(index)
                                ? 'bg-emerald-500 text-white'
                                : wrong.has(index)
                                  ? 'animate-pulse bg-rose-500 text-white'
                                  : 'bg-white/10 text-white',
                        )}
                        aria-label={`Letra ${index + 1}`}
                    >
                        {letter}
                    </button>
                ))}
            </div>
        </ChallengeFrame>
    );
}
