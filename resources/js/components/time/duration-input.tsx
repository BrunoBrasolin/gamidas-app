import { useState } from 'react';
import type { ComponentProps } from 'react';
import { Input } from '@/components/ui/input';
import { formatClock, formatMinutes, parseDuration } from '@/lib/time';

type DurationInputProps = Omit<
    ComponentProps<'input'>,
    'name' | 'value' | 'defaultValue' | 'onChange' | 'type'
> & {
    name: string;
    defaultMinutes?: number | null;
};

/**
 * Duration typed as "1:30", "1h30", "45m" or "1,5h". The form submits whole
 * minutes in a hidden input; an unreadable value submits nothing, so the
 * server reports the field as required.
 */
export function DurationInput({
    name,
    defaultMinutes = null,
    ...props
}: DurationInputProps) {
    const [text, setText] = useState(
        defaultMinutes ? formatClock(defaultMinutes) : '',
    );
    const minutes = parseDuration(text);

    return (
        <>
            <Input
                {...props}
                type="text"
                inputMode="text"
                autoComplete="off"
                placeholder={props.placeholder ?? '1:30'}
                value={text}
                aria-invalid={minutes === null || props['aria-invalid']}
                onChange={(event) => setText(event.target.value)}
            />
            <input
                type="hidden"
                name={name}
                value={minutes === null || minutes === 0 ? '' : minutes}
            />
            <span className="text-xs text-muted-foreground" aria-live="polite">
                {minutes === null
                    ? 'Use 1:30, 1h30, 45m ou 1,5h.'
                    : minutes > 0
                      ? `= ${formatMinutes(minutes)}`
                      : 'Ex.: 1:30, 1h30, 45m ou 1,5h'}
            </span>
        </>
    );
}
