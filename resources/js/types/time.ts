export type TaskStatus = 'active' | 'completed' | 'archived';

export type TaskGoalType = 'none' | 'time' | 'outcome' | 'time_and_outcome';

export type TimerStatus = 'running' | 'paused' | 'stopped';

export type TaskOption = {
    id: number;
    title: string;
    status: TaskStatus;
};

export type TaskProgress = {
    recorded_minutes: number;
    percent: number | null;
    remaining_minutes: number | null;
    outcome_done: boolean | null;
    overdue: boolean;
    days_left: number | null;
};

export type Task = {
    id: number;
    title: string;
    description: string | null;
    goal_type: TaskGoalType;
    target_minutes: number | null;
    outcome: string | null;
    deadline: string | null;
    status: TaskStatus;
    completed_at: string | null;
    archived_at: string | null;
    created_at: string | null;
    has_history: boolean;
    progress: TaskProgress;
};

export type TimerSession = {
    id: number;
    status: TimerStatus;
    task: { id: number; title: string; archived: boolean };
    started_at: string;
    started_on: string;
    paused_at: string | null;
    ended_at: string | null;
    /** Elapsed seconds computed by the server when the page was rendered. */
    elapsed_seconds: number;
};

export type TimeEntry = {
    id: number;
    task_id: number;
    date: string;
    minutes: number;
    description: string | null;
    updated_at: string | null;
};

export type DailyNote = {
    date: string;
    content: string;
    revision: number;
    updated_at: string | null;
};

/** Values used to pre-fill a new manual entry (never saved automatically). */
export type EntryDraft = {
    task_id?: number;
    date?: string;
    minutes?: number;
    description?: string;
};
