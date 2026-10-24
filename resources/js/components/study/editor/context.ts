import { createContext, useContext } from 'react';
import type { StudyAttachment, StudyAttachmentMap } from '@/types';
import type { UploadKind, UploadStore } from './uploads';

export type StudyEditorContextValue = {
    attachments: StudyAttachmentMap;
    uploads: UploadStore;
    /** Send a file and resolve with the attachment (null when it failed). */
    uploadReplacement: (
        file: File,
        kind: UploadKind,
    ) => Promise<StudyAttachment | null>;
    retryUpload: (uploadId: string) => void;
    cancelUpload: (uploadId: string) => void;
};

export const StudyEditorContext =
    createContext<StudyEditorContextValue | null>(null);

export function useStudyEditor(): StudyEditorContextValue {
    const value = useContext(StudyEditorContext);

    if (!value) {
        throw new Error('useStudyEditor must be used inside the study editor.');
    }

    return value;
}
