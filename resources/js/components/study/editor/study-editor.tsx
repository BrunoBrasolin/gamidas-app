import type { JSONContent } from '@tiptap/core';
import { TableKit } from '@tiptap/extension-table';
import { Placeholder } from '@tiptap/extensions';
import type { Editor } from '@tiptap/react';
import { EditorContent, useEditor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { formatBytes, safeHref } from '@/lib/study';
import type { StudyAttachment, StudyAttachmentMap, StudyNode } from '@/types';
import { StudyEditorContext } from './context';
import type { StudyEditorContextValue } from './context';
import { StudyFile, StudyImage, StudyUpload } from './nodes';
import { EditorToolbar } from './toolbar';
import { sendFile, UploadStore, uploadKind } from './uploads';
import type { UploadKind } from './uploads';

export type UploadLimits = { image_kb: number; pdf_kb: number };

/**
 * The document without upload placeholders, ready to be saved.
 */
export function savableContent(editor: Editor): JSONContent {
    const strip = (node: JSONContent): JSONContent => ({
        ...node,
        ...(node.content
            ? {
                  content: node.content
                      .filter((child) => child.type !== 'studyUpload')
                      .map(strip),
              }
            : {}),
    });

    return strip(editor.getJSON());
}

function newId(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

/**
 * The rich-text editor of a study page (Tiptap/ProseMirror). Markdown
 * shortcuts work while typing ("# ", "- ", "> ", "**bold**"…), the toolbar
 * covers the rest. Images and PDFs are uploaded as they are picked, dropped
 * or pasted, and placed as blocks between paragraphs.
 */
export function StudyEditor({
    content,
    initialAttachments,
    uploadUrl,
    limits,
    onChange,
    onReady,
}: {
    content: StudyNode;
    initialAttachments: StudyAttachmentMap;
    uploadUrl: string;
    limits: UploadLimits;
    onChange: () => void;
    onReady: (editor: Editor) => void;
}) {
    const [attachments, setAttachments] = useState<StudyAttachmentMap>(() =>
        Array.isArray(initialAttachments) ? {} : initialAttachments,
    );
    const [uploads] = useState(() => new UploadStore());
    const editorRef = useRef<Editor | null>(null);

    function remember(attachment: StudyAttachment) {
        setAttachments((current) => ({
            ...current,
            [String(attachment.id)]: attachment,
        }));
    }

    /** A message when the file can't be sent, or null when it can. */
    function rejection(file: File, kind: UploadKind | null): string | null {
        if (kind === null) {
            return `"${file.name}" não é uma imagem (JPEG, PNG, WebP, GIF) nem um PDF.`;
        }

        const max = (kind === 'image' ? limits.image_kb : limits.pdf_kb) * 1024;

        if (file.size > max) {
            return `"${file.name}" tem ${formatBytes(file.size)}; o limite é ${formatBytes(max)}.`;
        }

        return null;
    }

    function findUpload(id: string): { pos: number; size: number } | null {
        const editor = editorRef.current;
        let found: { pos: number; size: number } | null = null;

        editor?.state.doc.descendants((node, pos) => {
            if (found) {
                return false;
            }

            if (
                node.type.name === 'studyUpload' &&
                node.attrs.uploadId === id
            ) {
                found = { pos, size: node.nodeSize };

                return false;
            }

            return true;
        });

        return found;
    }

    function start(id: string) {
        const upload = uploads.get(id);

        if (!upload) {
            return;
        }

        uploads.update(id, {
            status: 'uploading',
            progress: 0,
            error: undefined,
        });

        sendFile(uploadUrl, upload.file, (progress) =>
            uploads.update(id, { progress }),
        )
            .then((attachment) => {
                remember(attachment);
                uploads.delete(id);

                const editor = editorRef.current;
                const place = findUpload(id);

                if (!editor || !place) {
                    return;
                }

                const type =
                    attachment.kind === 'image' ? 'studyImage' : 'studyFile';

                editor
                    .chain()
                    .command(({ tr, state }) => {
                        tr.replaceWith(
                            place.pos,
                            place.pos + place.size,
                            state.schema.nodes[type].create({
                                attachmentId: attachment.id,
                            }),
                        );
                        // Undo should not bring the placeholder back.
                        tr.setMeta('addToHistory', false);

                        return true;
                    })
                    .run();
            })
            .catch((exception: Error) => {
                uploads.update(id, {
                    status: 'error',
                    error: exception.message,
                });
            });
    }

    function insertFiles(files: File[], at?: number) {
        const editor = editorRef.current;

        if (!editor) {
            return;
        }

        const accepted: { id: string; file: File; kind: UploadKind }[] = [];

        for (const file of files) {
            const kind = uploadKind(file);
            const problem = rejection(file, kind);

            if (problem || kind === null) {
                toast.error(problem);
                continue;
            }

            accepted.push({ id: newId(), file, kind });
        }

        if (accepted.length === 0) {
            return;
        }

        for (const item of accepted) {
            uploads.set(item.id, { ...item, progress: 0, status: 'uploading' });
        }

        const { from, to } = editor.state.selection;

        editor
            .chain()
            .focus()
            .insertContentAt(
                at ?? { from, to },
                accepted.map((item) => ({
                    type: 'studyUpload',
                    attrs: { uploadId: item.id },
                })),
            )
            .run();

        accepted.forEach((item) => start(item.id));
    }

    // The editor props are created once; they reach the latest handler here.
    const insertFilesRef = useRef(insertFiles);

    useEffect(() => {
        insertFilesRef.current = insertFiles;
    });

    const context: StudyEditorContextValue = {
        attachments,
        uploads,
        uploadReplacement: async (file, kind) => {
            const problem = rejection(
                file,
                uploadKind(file) === kind ? kind : null,
            );

            if (problem) {
                toast.error(problem);

                return null;
            }

            try {
                const attachment = await sendFile(uploadUrl, file, () => {});
                remember(attachment);

                return attachment;
            } catch (exception) {
                toast.error((exception as Error).message);

                return null;
            }
        },
        retryUpload: (id) => start(id),
        cancelUpload: (id) => {
            uploads.delete(id);
            const place = findUpload(id);

            if (place) {
                editorRef.current
                    ?.chain()
                    .command(({ tr }) => {
                        tr.delete(place.pos, place.pos + place.size);
                        tr.setMeta('addToHistory', false);

                        return true;
                    })
                    .run();
            }
        },
    };

    const extensions = useMemo(
        () => [
            StarterKit.configure({
                heading: { levels: [1, 2, 3] },
                link: {
                    openOnClick: false,
                    autolink: true,
                    defaultProtocol: 'https',
                    protocols: ['mailto'],
                    isAllowedUri: (url) => safeHref(url) !== null,
                    HTMLAttributes: {
                        rel: 'noopener noreferrer',
                        target: null,
                    },
                },
            }),
            TableKit.configure({ table: { resizable: false } }),
            Placeholder.configure({
                placeholder:
                    'Comece a escrever… Use a barra acima para formatar ou inserir imagens e PDFs.',
            }),
            StudyImage,
            StudyFile,
            StudyUpload,
        ],
        [],
    );

    const editor = useEditor({
        extensions,
        content: content as JSONContent,
        shouldRerenderOnTransaction: false,
        editorProps: {
            attributes: {
                class: 'study-prose',
                'aria-label': 'Conteúdo da página',
                spellcheck: 'true',
                autocapitalize: 'sentences',
            },
            handleDrop: (view, event, _slice, moved) => {
                const files = Array.from(event.dataTransfer?.files ?? []);

                if (moved || files.length === 0) {
                    return false;
                }

                event.preventDefault();
                const at = view.posAtCoords({
                    left: event.clientX,
                    top: event.clientY,
                })?.pos;
                insertFilesRef.current(files, at);

                return true;
            },
            handlePaste: (_view, event) => {
                const files = Array.from(event.clipboardData?.files ?? []);

                if (files.length === 0) {
                    return false;
                }

                insertFilesRef.current(files);

                return true;
            },
        },
        onCreate: ({ editor: created }) => {
            editorRef.current = created;
            onReady(created);
        },
        onUpdate: () => onChange(),
    });

    if (!editor) {
        return null;
    }

    return (
        <StudyEditorContext.Provider value={context}>
            <div className="sticky top-14 z-20 -mx-4 border-y border-paper-edge bg-paper/95 backdrop-blur supports-[backdrop-filter]:bg-paper/80 sm:mx-0 sm:rounded-xl sm:border">
                <EditorToolbar
                    editor={editor}
                    onPickFiles={(files) => insertFiles(files)}
                />
            </div>
            <EditorContent editor={editor} className="pt-6 pb-[40vh]" />
        </StudyEditorContext.Provider>
    );
}
