export type EventColor =
    | 'slate'
    | 'red'
    | 'orange'
    | 'amber'
    | 'green'
    | 'teal'
    | 'blue'
    | 'violet'
    | 'pink';

export type EventIcon =
    | 'calendar'
    | 'cake'
    | 'party'
    | 'heart'
    | 'health'
    | 'work'
    | 'study'
    | 'travel'
    | 'home'
    | 'car'
    | 'shopping'
    | 'money'
    | 'family'
    | 'reminder'
    | 'pet'
    | 'sport'
    | 'music';

export type RecurrenceFrequency = 'daily' | 'weekly' | 'monthly' | 'yearly';

export type Recurrence = {
    frequency: RecurrenceFrequency;
    /** Repeats every `interval` days, weeks, months or years. */
    interval: number;
    /** Weekdays of a weekly series, 0 = Sunday … 6 = Saturday. */
    weekdays: number[];
    /** Last possible date, "YYYY-MM-DD", or null when it never ends. */
    until: string | null;
};

/**
 * One event on the agenda: a single event, or one occurrence of a series.
 */
export type CalendarEvent = {
    /** Id of the event or series. */
    id: number;
    /** Original date of the occurrence within its series, null for single events. */
    occurrence: string | null;
    title: string;
    /** "YYYY-MM-DD" */
    date: string;
    /** "HH:MM", or null for an all-day event. */
    time: string | null;
    description: string | null;
    color: EventColor;
    icon: EventIcon;
    /** Whether this occurrence was changed on its own. */
    modified: boolean;
    recurrence: Recurrence | null;
    /** Values of the whole series, for editing it from any occurrence. */
    series: Pick<
        CalendarEvent,
        'title' | 'date' | 'time' | 'description' | 'color' | 'icon'
    > | null;
    /** Id of the show this entry stands for; shows are edited on their own page. */
    show_id: number | null;
    created_by: string | null;
    created_at: string | null;
};
