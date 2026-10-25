import { createContext, useContext } from 'react';
import type { KnowledgeAttachment, KnowledgeAttachmentMap } from '@/types';
import type { UploadKind, UploadStore } from './uploads';

export type KnowledgeEditorContextValue = {
    attachments: KnowledgeAttachmentMap;
    uploads: UploadStore;
    /** Send a file and resolve with the attachment (null when it failed). */
    uploadReplacement: (
        file: File,
        kind: UploadKind,
    ) => Promise<KnowledgeAttachment | null>;
    retryUpload: (uploadId: string) => void;
    cancelUpload: (uploadId: string) => void;
};

export const KnowledgeEditorContext =
    createContext<KnowledgeEditorContextValue | null>(null);

export function useKnowledgeEditor(): KnowledgeEditorContextValue {
    const value = useContext(KnowledgeEditorContext);

    if (!value) {
        throw new Error(
            'useKnowledgeEditor must be used inside the knowledge editor.',
        );
    }

    return value;
}
