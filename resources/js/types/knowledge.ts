/** Accent colors of a subject's cover (app/Enums/KnowledgeColor.php). */
export type KnowledgeColor =
    | 'terracotta'
    | 'ochre'
    | 'olive'
    | 'sage'
    | 'teal'
    | 'navy'
    | 'plum'
    | 'rose'
    | 'graphite';

export type KnowledgePageStatus = 'unread' | 'in_progress' | 'reviewed';

export type KnowledgeSubjectCounts = {
    pages: number;
    categories: number;
    attachments: number;
    reviewed: number;
    in_progress: number;
};

/** A subject (book) of the knowledge base. */
export type KnowledgeSubject = {
    id: number;
    title: string;
    description: string | null;
    accent_color: KnowledgeColor;
    cover_url: string | null;
    is_pinned: boolean;
    sort_order: number;
    archived: boolean;
    /** ISO timestamps */
    updated_at: string | null;
    last_opened_at: string | null;
    counts: KnowledgeSubjectCounts;
};

export type KnowledgePageLink = { id: number; title: string };

export type KnowledgeLibrarySubject = KnowledgeSubject & {
    continue_page: KnowledgePageLink | null;
};

/** A category of a subject; parent_id nests it inside another one. */
export type KnowledgeCategory = {
    id: number;
    parent_id: number | null;
    title: string;
    sort_order: number;
};

export type KnowledgePageSummary = {
    id: number;
    subject_id: number;
    category_id: number | null;
    title: string;
    status: KnowledgePageStatus;
    sort_order: number;
    bookmarked: boolean;
    archived: boolean;
    attachments_count: number;
    updated_at: string | null;
    last_read_at: string | null;
};

export type KnowledgeSubjectRef = {
    id: number;
    title: string;
    accent_color: KnowledgeColor;
};

export type KnowledgePageWithSubject = KnowledgePageSummary & {
    subject: KnowledgeSubjectRef;
};

/** A subject a page can be moved to, with its categories. */
export type KnowledgeDestination = {
    id: number;
    title: string;
    categories: KnowledgeCategory[];
};

export type KnowledgeAttachment = {
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
export type KnowledgeAttachmentMap = Record<string, KnowledgeAttachment>;

/** A node of the editor document (Tiptap/ProseMirror JSON). */
export type KnowledgeNode = {
    type: string;
    attrs?: Record<string, unknown>;
    content?: KnowledgeNode[];
    text?: string;
    marks?: { type: string; attrs?: Record<string, unknown> }[];
};

export type KnowledgePageDetail = KnowledgePageSummary & {
    content: KnowledgeNode;
    category: string | null;
};

export type KnowledgeSearchResults = {
    subjects: KnowledgeSubject[];
    categories: (KnowledgeCategory & { subject: KnowledgeSubjectRef })[];
    pages: (KnowledgePageWithSubject & { excerpt: string | null })[];
};
