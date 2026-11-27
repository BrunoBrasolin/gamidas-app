import { tierClass } from '@/lib/tiers';
import { cn } from '@/lib/utils';
import type { Tier } from '@/types';

/** A tier letter in its color. */
export function TierBadge({
    tier,
    className,
}: {
    tier: Tier;
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
