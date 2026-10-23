export type TimeCategory = {
    id: number;
    name: string;
    daily_goal_seconds: number | null;
    archived: boolean;
    has_history: boolean;
    month_seconds: number;
};

export type CategoryOption = {
    id: number;
    name: string;
    archived: boolean;
};

/** One sheet cell: the time and Markdown note of a category on a date. */
export type TimeCell = {
    seconds: number;
    note: string | null;
};

/** The stopwatch of a category; times are epoch milliseconds from the server clock. */
export type CategoryTimer = {
    category_id: number;
    name: string;
    archived: boolean;
    accumulated_seconds: number;
    started_at: number | null;
};

export type TimerList = { now: number; items: CategoryTimer[] };
