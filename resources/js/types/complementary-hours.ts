export type ComplementaryAttachmentKind = 'certificate' | 'report' | 'other';

/** A file attached to a complementary activity. */
export type ComplementaryAttachment = {
    id: number;
    kind: ComplementaryAttachmentKind;
    name: string;
    mime: string;
    /** Bytes. */
    size: number;
    is_image: boolean;
    is_pdf: boolean;
};

export type ComplementaryActivity = {
    id: number;
    title: string;
    /** "YYYY-MM-DD", or null when not informed. */
    completed_on: string | null;
    minutes: number;
    /** Whether the college's report form was already filled in. */
    report_done: boolean;
    /** Certificates first, then reports, then other files. */
    attachments: ComplementaryAttachment[];
};

/** Durations in minutes; required/remaining are null until a goal is set. */
export type ComplementaryHoursSummary = {
    required_minutes: number | null;
    done_minutes: number;
    remaining_minutes: number | null;
    activities: number;
    without_report: number;
};
