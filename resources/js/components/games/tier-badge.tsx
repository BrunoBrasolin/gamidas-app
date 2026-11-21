import { tierClass } from '@/lib/games';
import { cn } from '@/lib/utils';
import type { GameTier } from '@/types';

/** A tier letter in its color. */
export function TierBadge({
    tier,
    className,
}: {
    tier: GameTier;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex size-6 items-center justify-center rounded-md text-xs font-bold shadow-sm',
                tierClass(tier),
                className,
            )}
            title={`Tier ${tier}`}
        >
            {tier}
        </span>
    );
}
