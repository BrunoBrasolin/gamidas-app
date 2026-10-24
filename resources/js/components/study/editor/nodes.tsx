import { Node } from '@tiptap/core';
import type { NodeViewProps } from '@tiptap/react';
import { NodeViewWrapper, ReactNodeViewRenderer } from '@tiptap/react';
import { AlertCircle, RefreshCw, Replace, Trash2, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { useRef, useState, useSyncExternalStore } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatBytes } from '@/lib/study';
import {
    MissingAttachment,
    PdfCard,
    StudyFigure,
} from '../attachment-viewers';
import { useStudyEditor } from './context';
import { IMAGE_ACCEPT, PDF_ACCEPT } from './uploads';
import type { UploadKind } from './uploads';

function idFrom(element: HTMLElement): number | null {
    const id = Number(element.getAttribute('data-attachment-id'));

    return Number.isInteger(id) && id > 0 ? id : null;
}

function BlockActions({
    kind,
    onReplace,
    onRemove,
}: {
    kind: UploadKind;
    onReplace: (file: File) => void;
    onRemove: () => void;
}) {
    const input = useRef<HTMLInputElement>(null);
    const label = kind === 'image' ? 'imagem' : 'PDF';

    return (
        <>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-10"
                aria-label={`Substituir ${label}`}
                title={`Substituir ${label}`}
                onClick={() => input.current?.click()}
            >
                <Replace />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-10 text-destructive hover:text-destructive"
                aria-label={`Remover ${label}`}
                title={`Remover ${label}`}
                onClick={onRemove}
            >
                <Trash2 />
            </Button>
            <input
                ref={input}
                type="file"
                className="sr-only"
                tabIndex={-1}
                accept={kind === 'image' ? IMAGE_ACCEPT : PDF_ACCEPT}
                onChange={(event) => {
                    const file = event.target.files?.[0];
                    event.target.value = '';

                    if (file) {
                        onReplace(file);
                    }
                }}
            />
        </>
    );
}

/**
 * Shared node view of an attachment block (image or PDF), with replace and
 * remove controls always visible (no hover-only actions on touch screens).
 */
function AttachmentBlockView({
    node,
    updateAttributes,
    deleteNode,
    kind,
}: NodeViewProps & { kind: UploadKind }) {
    const { attachments, uploadReplacement } = useStudyEditor();
    const [replacing, setReplacing] = useState(false);
    const id = node.attrs.attachmentId as number | null;
    const attachment = id === null ? undefined : attachments[String(id)];
    const alt = (node.attrs.alt as string | null) ?? '';

    function replace(file: File) {
        setReplacing(true);
        void uploadReplacement(file, kind).then((stored) => {
            setReplacing(false);

            if (stored) {
                updateAttributes({ attachmentId: stored.id });
                toast.success(
                    kind === 'image' ? 'Imagem substituída.' : 'PDF substituído.',
                );
            }
        });
    }

    const actions: ReactNode = replacing ? (
        <span className="flex size-10 items-center justify-center">
            <Spinner />
        </span>
    ) : (
        <BlockActions kind={kind} onReplace={replace} onRemove={deleteNode} />
    );

    return (
        <NodeViewWrapper
            className="my-[1.2em]"
            data-drag-handle
            contentEditable={false}
        >
            {!attachment ? (
                <div className="relative">
                    <MissingAttachment kind={kind} />
                    <div className="absolute top-2 right-2 flex gap-1">
                        {actions}
                    </div>
                </div>
            ) : kind === 'image' ? (
                <div className="space-y-2">
                    <StudyFigure
                        attachment={attachment}
                        alt=""
                        actions={actions}
                    />
                    <input
                        type="text"
                        value={alt}
                        maxLength={300}
                        placeholder="Legenda (opcional)"
                        aria-label="Legenda da imagem"
                        className="block w-full rounded-md border-0 bg-transparent px-2 py-1 text-center font-sans text-sm text-ink-soft outline-none placeholder:text-ink-soft/60 focus-visible:ring-2 focus-visible:ring-ring"
                        onChange={(event) =>
                            updateAttributes({ alt: event.target.value || null })
                        }
                    />
                </div>
            ) : (
                <PdfCard attachment={attachment} actions={actions} />
            )}
        </NodeViewWrapper>
    );
}

function ImageView(props: NodeViewProps) {
    return <AttachmentBlockView {...props} kind="image" />;
}

function FileView(props: NodeViewProps) {
    return <AttachmentBlockView {...props} kind="pdf" />;
}

/**
 * An image placed between paragraphs, referencing a stored attachment.
 */
export const StudyImage = Node.create({
    name: 'studyImage',
    group: 'block',
    atom: true,
    draggable: true,
    selectable: true,

    addAttributes() {
        return {
            attachmentId: {
                default: null,
                parseHTML: idFrom,
                renderHTML: (attrs) => ({
                    'data-attachment-id': attrs.attachmentId as number,
                }),
            },
            alt: {
                default: null,
                parseHTML: (element) => element.getAttribute('data-alt'),
                renderHTML: (attrs) =>
                    attrs.alt ? { 'data-alt': attrs.alt as string } : {},
            },
        };
    },

    parseHTML() {
        return [{ tag: 'figure[data-study-image]' }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['figure', { ...HTMLAttributes, 'data-study-image': '' }];
    },

    addNodeView() {
        return ReactNodeViewRenderer(ImageView);
    },
});

/**
 * A PDF attached at a place of the text, shown as a document card.
 */
export const StudyFile = Node.create({
    name: 'studyFile',
    group: 'block',
    atom: true,
    draggable: true,
    selectable: true,

    addAttributes() {
        return {
            attachmentId: {
                default: null,
                parseHTML: idFrom,
                renderHTML: (attrs) => ({
                    'data-attachment-id': attrs.attachmentId as number,
                }),
            },
        };
    },

    parseHTML() {
        return [{ tag: 'div[data-study-file]' }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['div', { ...HTMLAttributes, 'data-study-file': '' }];
    },

    addNodeView() {
        return ReactNodeViewRenderer(FileView);
    },
});

function UploadView({ node }: NodeViewProps) {
    const { uploads, retryUpload, cancelUpload } = useStudyEditor();
    const id = node.attrs.uploadId as string;
    const upload = useSyncExternalStore(uploads.subscribe, () =>
        uploads.get(id),
    );

    return (
        <NodeViewWrapper className="my-[1.2em]" contentEditable={false}>
            <div
                className="flex flex-wrap items-center gap-3 rounded-xl border border-dashed border-paper-edge bg-background/60 p-3 font-sans"
                role="status"
            >
                {upload?.status === 'error' ? (
                    <AlertCircle
                        className="size-5 shrink-0 text-destructive"
                        aria-hidden
                    />
                ) : (
                    <Spinner />
                )}
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium text-ink">
                        {upload?.file.name ?? 'Arquivo'}
                        {upload && (
                            <span className="ml-2 font-normal text-ink-soft">
                                {formatBytes(upload.file.size)}
                            </span>
                        )}
                    </p>
                    {upload?.status === 'error' ? (
                        <p className="text-sm text-destructive">
                            {upload.error}
                        </p>
                    ) : (
                        <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-paper-edge">
                            <div
                                className="h-full bg-primary transition-[width]"
                                style={{ width: `${upload?.progress ?? 0}%` }}
                            />
                        </div>
                    )}
                </div>
                {upload?.status === 'error' && (
                    <Button
                        type="button"
                        variant="outline"
                        className="h-10"
                        onClick={() => retryUpload(id)}
                    >
                        <RefreshCw />
                        Tentar de novo
                    </Button>
                )}
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-10"
                    aria-label="Cancelar envio"
                    onClick={() => cancelUpload(id)}
                >
                    <X />
                </Button>
            </div>
        </NodeViewWrapper>
    );
}

/**
 * Placeholder for a file being sent. Never saved: it is stripped from the
 * document before autosave and replaced by the real block once stored.
 */
export const StudyUpload = Node.create({
    name: 'studyUpload',
    group: 'block',
    atom: true,
    selectable: false,

    addAttributes() {
        return { uploadId: { default: null, rendered: false } };
    },

    renderHTML() {
        return ['div', { 'data-study-upload': '' }];
    },

    addNodeView() {
        return ReactNodeViewRenderer(UploadView);
    },
});
