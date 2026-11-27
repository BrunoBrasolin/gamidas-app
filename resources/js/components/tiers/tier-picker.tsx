import { TIERS } from '@/lib/tiers';
import { cn } from '@/lib/utils';
import type { Tier } from '@/types';

/**
 * The tier of one item, from its own page: pressing a letter sets it,
 * pressing the current one takes the item off the list.
 */
export function TierPicker({
    value,
    onChange,
}: {
    value: Tier | null;
    onChange: (tier: Tier | null) => void;
}) {
    return (
        <div className="flex flex-wrap gap-1" role="group" aria-label="Tier">
            {TIERS.map(({ tier, className }) => (
                <button
                    key={tier}
                    type="button"
                    aria-pressed={value === tier}
                    onClick={() => onChange(value === tier ? null : tier)}
                    className={cn(
                        'size-8 rounded-md text-sm font-bold transition-opacity',
                        className,
                        value === null || value === tier
                            ? 'opacity-100'
                            : 'opacity-40 hover:opacity-80',
                        value === tier &&
                            'ring-2 ring-foreground ring-offset-1 ring-offset-background',
                    )}
                >
                    {tier}
                </button>
            ))}
        </div>
    );
}
