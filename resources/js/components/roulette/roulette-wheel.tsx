import {
    forwardRef,
    useEffect,
    useImperativeHandle,
    useRef,
    useState,
} from 'react';
import type { RoulettePlace } from '@/types';

export type RouletteWheelHandle = {
    /** Spin and resolve with the place the wheel stopped on. */
    spin: () => Promise<RoulettePlace>;
};

const VIEW = 100;
const RADIUS = 96;
const SPIN_MS = 6000;
const REDUCED_SPIN_MS = 1200;

/** Uniform random integer in [0, max), from the browser's crypto when available. */
function randomInt(max: number): number {
    if (typeof crypto !== 'undefined' && crypto.getRandomValues) {
        const limit = Math.floor(0x100000000 / max) * max;
        const buffer = new Uint32Array(1);

        do {
            crypto.getRandomValues(buffer);
        } while (buffer[0] >= limit);

        return buffer[0] % max;
    }

    return Math.floor(Math.random() * max);
}

/** Point at `angle` degrees clockwise from the top, `radius` from the centre. */
function polar(angle: number, radius: number): [number, number] {
    const radians = (angle * Math.PI) / 180;

    return [radius * Math.sin(radians), -radius * Math.cos(radians)];
}

function slicePath(start: number, end: number): string {
    const [x1, y1] = polar(start, RADIUS);
    const [x2, y2] = polar(end, RADIUS);
    const large = end - start > 180 ? 1 : 0;

    return `M0 0 L${x1} ${y1} A${RADIUS} ${RADIUS} 0 ${large} 1 ${x2} ${y2} Z`;
}

/** Where each place's slice starts and how wide it is, in degrees: proportional to its weight. */
function layoutSlices(places: RoulettePlace[]): {
    totalWeight: number;
    slices: { start: number; size: number }[];
} {
    const totalWeight = places.reduce((sum, place) => sum + place.weight, 0);
    let start = 0;

    const slices = places.map((place) => {
        const size = (place.weight / totalWeight) * 360;
        const slice = { start, size };

        start += size;

        return slice;
    });

    return { totalWeight, slices };
}

/** Slices alternate between two hues that stay readable behind white text. */
function sliceColor(index: number, total: number): string {
    const hue = (index * 360) / total;
    const lightness = index % 2 === 0 ? 42 : 36;

    return `hsl(${hue} 62% ${lightness}%)`;
}

function fontSizeFor(total: number): number {
    if (total <= 6) {
        return 7;
    }

    if (total <= 12) {
        return 5.5;
    }

    if (total <= 24) {
        return 4;
    }

    return 3;
}

const AVAILABLE = 66;
const CHAR_WIDTH = 0.58;

/** Shrink long names (down to 60% of the base size) before cutting them. */
function fitName(name: string, base: number): { text: string; size: number } {
    const size = Math.max(
        base * 0.6,
        Math.min(base, AVAILABLE / (name.length * CHAR_WIDTH)),
    );
    const max = Math.floor(AVAILABLE / (size * CHAR_WIDTH));

    return {
        text: name.length > max ? `${name.slice(0, max - 1)}…` : name,
        size,
    };
}

/**
 * The wheel: one slice per place, as wide as its weight, spun with a CSS
 * transition that decelerates until the chosen slice is under the pointer at
 * the top. The winner is drawn first (each place is picked with a chance
 * proportional to its weight) and the wheel is then rotated to land on it.
 */
export const RouletteWheel = forwardRef<
    RouletteWheelHandle,
    { places: RoulettePlace[]; highlightId: number | null }
>(function RouletteWheel({ places, highlightId }, ref) {
    const [rotation, setRotation] = useState(0);
    const [duration, setDuration] = useState(SPIN_MS);
    const rotationRef = useRef(0);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(
        () => () => {
            if (timer.current) {
                clearTimeout(timer.current);
            }
        },
        [],
    );

    useImperativeHandle(
        ref,
        () => ({
            spin() {
                return new Promise<RoulettePlace>((resolve) => {
                    const layout = layoutSlices(places);
                    let ticket = randomInt(layout.totalWeight);
                    let winner = 0;

                    while (ticket >= places[winner].weight) {
                        ticket -= places[winner].weight;
                        winner++;
                    }

                    const { start, size } = layout.slices[winner];
                    const reduced = window.matchMedia(
                        '(prefers-reduced-motion: reduce)',
                    ).matches;

                    // Stop anywhere inside the winning slice, not always at its middle.
                    const stop =
                        start + size / 2 + (Math.random() - 0.5) * size * 0.7;
                    const target = (((360 - stop) % 360) + 360) % 360;
                    const base = Math.ceil(rotationRef.current / 360) * 360;
                    const turns = reduced ? 1 : 5 + randomInt(3);
                    const next = base + turns * 360 + target;

                    rotationRef.current = next;
                    setDuration(reduced ? REDUCED_SPIN_MS : SPIN_MS);
                    setRotation(next);

                    if (timer.current) {
                        clearTimeout(timer.current);
                    }

                    timer.current = setTimeout(
                        () => resolve(places[winner]),
                        (reduced ? REDUCED_SPIN_MS : SPIN_MS) + 100,
                    );
                });
            },
        }),
        [places],
    );

    const total = places.length;
    const { slices } = layoutSlices(places);
    const fontSize = fontSizeFor(total);

    return (
        <div className="relative mx-auto aspect-square w-full max-w-md">
            {/* Pointer */}
            <div
                aria-hidden
                className="absolute top-0 left-1/2 z-10 h-0 w-0 -translate-x-1/2 -translate-y-1 border-x-[14px] border-t-[26px] border-x-transparent border-t-foreground drop-shadow"
            />
            <svg
                viewBox={`${-VIEW} ${-VIEW} ${VIEW * 2} ${VIEW * 2}`}
                role="img"
                aria-label={`Roleta com ${total} ${total === 1 ? 'opção' : 'opções'}`}
                className="size-full drop-shadow-lg"
            >
                <g
                    style={{
                        transform: `rotate(${rotation}deg)`,
                        transition: `transform ${duration}ms cubic-bezier(0.12, 0.72, 0.1, 1)`,
                    }}
                >
                    <circle r={RADIUS + 3} className="fill-foreground" />
                    {total === 0 && (
                        <circle r={RADIUS} className="fill-muted" />
                    )}
                    {total === 1 && (
                        <circle r={RADIUS} fill={sliceColor(0, 1)} />
                    )}
                    {places.map((place, index) => {
                        const { start, size } = slices[index];
                        const middle = start + size / 2;
                        // Thin slices (low weight, many places) get smaller text.
                        const label = fitName(
                            place.name,
                            Math.min(fontSize, Math.max(2.5, size * 0.5)),
                        );
                        const dimmed =
                            highlightId !== null && highlightId !== place.id;

                        return (
                            <g
                                key={place.id}
                                style={{
                                    opacity: dimmed ? 0.45 : 1,
                                    transition: 'opacity 400ms',
                                }}
                            >
                                {total > 1 && (
                                    <path
                                        d={slicePath(start, start + size)}
                                        fill={sliceColor(index, total)}
                                        stroke="white"
                                        strokeWidth={0.6}
                                    />
                                )}
                                <text
                                    transform={`rotate(${middle - 90})`}
                                    x={RADIUS - 6}
                                    fontSize={label.size}
                                    fontWeight={600}
                                    textAnchor="end"
                                    dominantBaseline="central"
                                    fill="white"
                                >
                                    {label.text}
                                </text>
                            </g>
                        );
                    })}
                </g>
                <circle
                    r={9}
                    className="fill-background stroke-foreground"
                    strokeWidth={2}
                />
            </svg>
        </div>
    );
});
