import type { Tier } from '@/types';

/** Tiers best first, with the color of each label. */
export const TIERS: { tier: Tier; className: string }[] = [
    { tier: 'S', className: 'bg-rose-400 text-rose-950' },
    { tier: 'A', className: 'bg-orange-300 text-orange-950' },
    { tier: 'B', className: 'bg-amber-200 text-amber-950' },
    { tier: 'C', className: 'bg-lime-300 text-lime-950' },
    { tier: 'D', className: 'bg-sky-300 text-sky-950' },
    { tier: 'F', className: 'bg-violet-300 text-violet-950' },
];

export function tierClass(tier: Tier): string {
    return TIERS.find((entry) => entry.tier === tier)?.className ?? '';
}
