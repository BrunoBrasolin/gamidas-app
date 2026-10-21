import { Repeat } from 'lucide-react';
import { EVENT_COLORS, EVENT_ICONS } from '@/lib/calendar';
import { cn } from '@/lib/utils';
import type { CalendarEvent } from '@/types';

/**
 * The event's icon inside a tile tinted with its badge color.
 */
export function EventIcon({
    event,
    className,
}: {
    event: Pick<CalendarEvent, 'color' | 'icon'>;
    className?: string;
}) {
    const Icon = EVENT_ICONS[event.icon].icon;

    return (
        <span
            className={cn(
                'flex size-9 shrink-0 items-center justify-center rounded-lg border-l-[3px]',
                EVENT_COLORS[event.color].badge,
                className,
            )}
            aria-hidden
        >
            <Icon className={cn('size-4', EVENT_COLORS[event.color].icon)} />
        </span>
    );
}

/**
 * A one-line badge with icon, time and title, used inside calendar days.
 */
export function EventChip({
    event,
    onClick,
    className,
}: {
    event: CalendarEvent;
    onClick?: () => void;
    className?: string;
}) {
    const Icon = EVENT_ICONS[event.icon].icon;
    const label = event.time ? `${event.time} ${event.title}` : event.title;

    return (
        <button
            type="button"
            title={label}
            onClick={(clickEvent) => {
                clickEvent.stopPropagation();
                onClick?.();
            }}
            className={cn(
                'flex w-full min-w-0 items-center gap-1 rounded-sm border-l-[3px] px-1.5 py-0.5 text-left text-xs leading-tight transition-[filter] outline-none hover:brightness-95 focus-visible:ring-2 focus-visible:ring-ring dark:hover:brightness-125 print:items-start',
                EVENT_COLORS[event.color].badge,
                className,
            )}
        >
            <Icon
                className={cn(
                    'size-3 shrink-0 print:mt-px',
                    EVENT_COLORS[event.color].icon,
                )}
                aria-hidden
            />
            {event.time && (
                <span className="shrink-0 font-semibold tabular-nums">
                    {event.time}
                </span>
            )}
            <span className="truncate font-medium print:line-clamp-2 print:whitespace-normal">
                {event.title}
            </span>
            {event.recurrence && (
                <Repeat
                    className="ml-auto size-2.5 shrink-0 opacity-60 print:hidden"
                    aria-label="Evento recorrente"
                />
            )}
        </button>
    );
}
