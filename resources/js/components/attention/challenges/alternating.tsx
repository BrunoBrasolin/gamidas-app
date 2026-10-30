import { useEffect, useRef, useState } from 'react';
import type { ShapeKind } from '@/components/attention/game-kit';
import { ChallengeFrame, Shape, plural } from '@/components/attention/game-kit';
import { shuffle } from '@/lib/attention';
import { cn } from '@/lib/utils';
import type { ChallengeOutcome } from '@/types';

const PHASE_LENGTH = 12;
const TARGETS_PER_PHASE = 5;
/** Shapes of the old rule shown after the switch, to catch perseveration. */
const LURES = 4;
const SHOW_MS = 1_800;
const GAP_MS = 250;
const SWITCH_MS = 2_200;

type Stimulus = { kind: ShapeKind; target: boolean; phase: 1 | 2 };

function phase(
    number: 1 | 2,
    target: ShapeKind,
    lure: ShapeKind,
    lures: number,
): Stimulus[] {
    const fillers: ShapeKind[] = ['triangle', 'diamond'];

    return shuffle([
        ...Array.from({ length: TARGETS_PER_PHASE }, () => ({
            kind: target,
            target: true,
            phase: number,
        })),
        ...Array.from({ length: lures }, () => ({
            kind: lure,
            target: false,
            phase: number,
        })),
        ...Array.from(
            { length: PHASE_LENGTH - TARGETS_PER_PHASE - lures },
            (_, index) => ({
                kind: fillers[index % fillers.length],
                target: false,
                phase: number,
            }),
        ),
    ]);
}

type Response = { tapped: boolean; reaction: number | null };

/**
 * Atenção alternada: a go/no-go task whose target switches from circles to
 * squares halfway through.
 */
export function AlternatingChallenge({
    onDone,
}: {
    onDone: (outcome: ChallengeOutcome) => void;
}) {
    const [stimuli] = useState(() => [
        ...phase(1, 'circle', 'square', 3),
        ...phase(2, 'square', 'circle', LURES),
    ]);
    const [step, setStep] = useState(0);
    const [visible, setVisible] = useState(true);
    const [switching, setSwitching] = useState(false);
    const [flash, setFlash] = useState<'hit' | 'error' | null>(null);
    const responses = useRef<Response[]>([]);
    const shownAt = useRef(0);
    const started = useRef(0);
    const done = useRef(false);
    const total = stimuli.length;
    const current = stimuli[Math.min(step, total - 1)];

    function finish() {
        if (done.current) {
            return;
        }

        done.current = true;
        let hits = 0;
        let misses = 0;
        let errors = 0;
        let perseverations = 0;
        const reactions: number[] = [];

        stimuli.forEach((stimulus, index) => {
            const response = responses.current[index] ?? {
                tapped: false,
                reaction: null,
            };

            if (stimulus.target && response.tapped) {
                hits++;
                reactions.push(response.reaction ?? 0);
            } else if (stimulus.target) {
                misses++;
            } else if (response.tapped) {
                errors++;

                if (stimulus.phase === 2 && stimulus.kind === 'circle') {
                    perseverations++;
                }
            }
        });

        const accuracy = Math.round(((total - misses - errors) / total) * 100);
        const reaction =
            reactions.length === 0
                ? null
                : Math.round(
                      reactions.reduce((sum, ms) => sum + ms, 0) /
                          reactions.length,
                  );

        onDone({
            correct: accuracy >= 90,
            accuracy,
            hits,
            errors,
            misses,
            duration_ms: Math.round(performance.now() - started.current),
            reaction_ms: reaction,
            details: { perseverations },
            summary:
                `Você acertou ${hits} de ${TARGETS_PER_PHASE * 2} alvos e fez ${plural(errors, 'toque errado', 'toques errados')}` +
                (perseverations > 0
                    ? ` — ${perseverations === errors ? (errors === 1 ? 'foi' : 'todos foram') : plural(perseverations, 'foi', 'foram')} no círculo depois que a regra mudou.`
                    : '.'),
        });
    }

    const finishRef = useRef(finish);

    useEffect(() => {
        finishRef.current = finish;
    });

    useEffect(() => {
        started.current = performance.now();
    }, []);

    useEffect(() => {
        if (step >= total) {
            finishRef.current();

            return;
        }

        if (switching) {
            const timer = window.setTimeout(() => {
                setSwitching(false);
                setVisible(true);
            }, SWITCH_MS);

            return () => window.clearTimeout(timer);
        }

        shownAt.current = performance.now();
        const hide = window.setTimeout(() => setVisible(false), SHOW_MS);
        const next = window.setTimeout(() => {
            const following = step + 1;
            setStep(following);
            setFlash(null);

            if (following === PHASE_LENGTH) {
                setSwitching(true);
            } else {
                setVisible(true);
            }
        }, SHOW_MS + GAP_MS);

        return () => {
            window.clearTimeout(hide);
            window.clearTimeout(next);
        };
    }, [step, switching, total]);

    function tap() {
        if (switching || step >= total || responses.current[step]?.tapped) {
            return;
        }

        const reaction = Math.round(performance.now() - shownAt.current);
        responses.current[step] = { tapped: true, reaction };
        setFlash(current.target ? 'hit' : 'error');
    }

    const rule = current.phase === 1 ? 'circle' : 'square';

    if (switching) {
        return (
            <div className="flex flex-1 animate-in flex-col items-center justify-center gap-4 rounded-3xl bg-amber-400 p-6 text-center text-slate-900 duration-300 zoom-in-90">
                <p className="text-4xl font-black">MUDOU A REGRA!</p>
                <p className="text-xl font-semibold">
                    Agora toque SÓ nos quadrados
                </p>
                <Shape kind="square" color="#0f172a" className="size-24" />
                <p className="text-sm font-medium">Círculos não valem mais!</p>
            </div>
        );
    }

    return (
        <ChallengeFrame
            title={
                <span className="inline-flex items-center gap-2">
                    Toque só no
                    <Shape kind={rule} className="size-6" />
                    {rule === 'circle' ? 'círculo' : 'quadrado'}
                </span>
            }
            hint={`${Math.min(step + 1, total)}/${total}`}
        >
            <button
                type="button"
                onPointerDown={tap}
                className={cn(
                    'flex flex-1 touch-manipulation items-center justify-center rounded-3xl border-4 bg-white/5 transition-colors select-none',
                    flash === 'hit' && 'border-emerald-400 bg-emerald-500/20',
                    flash === 'error' && 'border-rose-500 bg-rose-500/20',
                    flash === null && 'border-white/10',
                )}
                aria-label="Tocar"
            >
                {visible && (
                    <Shape
                        key={step}
                        kind={current.kind}
                        className="size-40 animate-in duration-100 zoom-in-75"
                    />
                )}
            </button>
        </ChallengeFrame>
    );
}
