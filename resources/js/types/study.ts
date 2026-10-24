/** Accent colors of a subject's cover (app/Enums/StudyColor.php). */
export type StudyColor =
    | 'terracotta'
    | 'ochre'
    | 'olive'
    | 'sage'
    | 'teal'
    | 'navy'
    | 'plum'
    | 'rose'
    | 'graphite';

export type StudyPageStatus = 'unread' | 'in_progress' | 'reviewed';

export type StudySubjectCounts = {
    pages: number;
    chapters: number;
    attachments: number;
    reviewed: number;
    in_progress: number;
};

/** A subject (book) of the study library. */
export type StudySubject = {
    id: number;
    title: string;
    description: string | null;
    accent_color: StudyColor;
    cover_url: string | null;
    is_pinned: boolean;
    sort_order: number;
    archived: boolean;
    /** ISO timestamps */
    updated_at: string | null;
    last_opened_at: string | null;
    counts: StudySubjectCounts;
};

export type StudyPageLink = { id: number; title: string };

export type StudyLibrarySubject = StudySubject & {
    continue_page: StudyPageLink | null;
};

export type StudyChapter = { id: number; title: string; sort_order: number };

export type StudyPageSummary = {
    id: number;
    subject_id: number;
    chapter_id: number | null;
    title: string;
    status: StudyPageStatus;
    sort_order: number;
    bookmarked: boolean;
    archived: boolean;
    attachments_count: number;
    updated_at: string | null;
    last_read_at: string | null;
};

export type StudySubjectRef = {
    id: number;
    title: string;
    accent_color: StudyColor;
};

export type StudyPageWithSubject = StudyPageSummary & {
    subject: StudySubjectRef;
};

/** A subject a page can be moved to, with its chapters. */
export type StudyDestination = {
    id: number;
    title: string;
    chapters: { id: number; title: string }[];
};

export type StudyAttachment = {
    id: number;
    kind: 'image' | 'pdf';
    name: string;
    /** Bytes */
    size: number;
    mime: string;
    width: number | null;
    height: number | null;
    url: string;
};

/** Attachments keyed by id (an empty map comes as an empty array). */
export type StudyAttachmentMap = Record<string, StudyAttachment>;

/** A node of the editor document (Tiptap/ProseMirror JSON). */
export type StudyNode = {
    type: string;
    attrs?: Record<string, unknown>;
    content?: StudyNode[];
    text?: string;
    marks?: { type: string; attrs?: Record<string, unknown> }[];
};

export type StudyPageDetail = StudyPageSummary & {
    content: StudyNode;
    chapter: string | null;
};

export type StudySearchResults = {
    subjects: StudySubject[];
    chapters: (StudyChapter & { subject: StudySubjectRef })[];
    pages: (StudyPageWithSubject & { excerpt: string | null })[];
};
