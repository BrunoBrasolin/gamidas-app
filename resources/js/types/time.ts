export type TimeCategory = {
    id: number;
    name: string;
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
