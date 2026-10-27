/** The certificate attached to a complementary activity. */
export type ComplementaryCertificate = {
    name: string;
    mime: string;
    /** Bytes. */
    size: number;
    is_image: boolean;
};

export type ComplementaryActivity = {
    id: number;
    title: string;
    /** "YYYY-MM-DD", or null when not informed. */
    completed_on: string | null;
    minutes: number;
    has_report: boolean;
    /** Markdown; only sent on the details and edit pages. */
    report: string | null;
    certificate: ComplementaryCertificate;
};

/** Durations in minutes; required/remaining are null until a goal is set. */
export type ComplementaryHoursSummary = {
    required_minutes: number | null;
    done_minutes: number;
    remaining_minutes: number | null;
    activities: number;
    without_report: number;
};
