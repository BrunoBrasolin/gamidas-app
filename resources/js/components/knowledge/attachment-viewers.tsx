import { Download, ExternalLink, FileText, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { lazy, Suspense, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { jsonRequest } from '@/lib/http';
import { formatBytes } from '@/lib/knowledge';
import { cn } from '@/lib/utils';
import knowledge from '@/routes/knowledge';
import type { KnowledgeAttachment, KnowledgeAttachmentKind } from '@/types';

function downloadUrl(attachment: KnowledgeAttachment): string {
    return `${attachment.url}?download=1`;
}

const DocumentReader = lazy(() => import('./document-reader'));

/** Badge of each kind of file, in the colors of their usual apps. */
const FILE_BADGES: Record<
    KnowledgeAttachmentKind,
    { label: string; className: string }
> = {
    image: {
        label: 'IMG',
        className: 'bg-violet-600/10 text-violet-700 dark:text-violet-400',
    },
    pdf: {
        label: 'PDF',
        className: 'bg-red-600/10 text-red-700 dark:text-red-400',
    },
    slides: {
        label: 'PPTX',
        className: 'bg-orange-600/10 text-orange-700 dark:text-orange-400',
    },
    document: {
        label: 'DOCX',
        className: 'bg-blue-600/10 text-blue-700 dark:text-blue-400',
    },
    sheet: {
        label: 'XLSX',
        className: 'bg-green-600/10 text-green-700 dark:text-green-400',
    },
};

/** Whether the built-in reader opens it (spreadsheets are only downloaded). */
function isReadable(kind: KnowledgeAttachmentKind): boolean {
    return kind === 'pdf' || kind === 'slides' || kind === 'document';
}

type Reading = { page: number; pages: number };

/**
 * The latest reading of each file in this visit. Pages restored from the
 * browser history carry older props, so this wins over them.
 */
const latestReading = new Map<number, Reading>();

function readingOf(attachment: KnowledgeAttachment): Reading | null {
    return (
        latestReading.get(attachment.id) ??
        (attachment.reading_page && attachment.page_count
            ? { page: attachment.reading_page, pages: attachment.page_count }
            : null)
    );
}

/**
 * Where the reader is in a file: kept for the card and sent to the server a
 * moment after the page stops changing (and right away when the reader closes
 * or the tab is hidden). Losing a reading position is harmless.
 */
function useFileReading(attachment: KnowledgeAttachment) {
    const [reading, setReading] = useState<Reading | null>(() =>
        readingOf(attachment),
    );
    const pending = useRef<Reading | null>(null);
    const timer = useRef<number | undefined>(undefined);

    function flush() {
        window.clearTimeout(timer.current);
        const next = pending.current;
        pending.current = null;

        if (next) {
            jsonRequest(
                'PUT',
                knowledge.attachments.reading.url(attachment.id),
                next,
            ).catch(() => {});
        }
    }

    const flushRef = useRef(flush);

    useEffect(() => {
        flushRef.current = flush;
    });

    useEffect(() => {
        const onHide = () => {
            if (document.visibilityState === 'hidden') {
                flushRef.current();
            }
        };

        document.addEventListener('visibilitychange', onHide);

        return () => {
            document.removeEventListener('visibilitychange', onHide);
            flushRef.current();
        };
    }, []);

    function report(page: number, pages: number) {
        const next = { page, pages };
        latestReading.set(attachment.id, next);
        setReading(next);
        pending.current = next;
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(flush, 1200);
    }

    return { reading, report, flush };
}

/**
 * A file shown as a document card: PDFs, presentations and Word files open in
 * the built-in reader (at the page where it was left), images in the image
 * viewer; spreadsheets are downloaded.
 */
export function FileCard({
    attachment,
    actions,
    details,
    className,
}: {
    attachment: KnowledgeAttachment;
    /** Extra controls (the editor puts replace/remove here). */
    actions?: ReactNode;
    /** An extra line under the name (where the file lives, say). */
    details?: ReactNode;
    className?: string;
}) {
    const [viewing, setViewing] = useState(false);
    const { reading, report, flush } = useFileReading(attachment);
    const kind = attachment.kind;
    const badge = FILE_BADGES[kind];
    const readable = isReadable(kind);
    const unit = kind === 'slides' ? 'Slide' : 'Página';

    let hint: string;

    if (kind === 'image') {
        hint = 'toque para ampliar';
    } else if (!readable) {
        hint = 'toque para baixar';
    } else if (reading && reading.page > 1) {
        hint = `${unit} ${reading.page} de ${reading.pages} · toque para continuar`;
    } else {
        hint = 'toque para abrir';
    }

    return (
        <div
            data-node-card
            className={cn(
                'not-prose flex flex-wrap items-center gap-3 rounded-xl border border-paper-edge bg-background/70 p-3 font-sans shadow-xs',
                className,
            )}
        >
            <button
                type="button"
                className="flex min-h-12 min-w-0 flex-1 items-center gap-3 rounded-lg text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring"
                onClick={() => {
                    if (readable || kind === 'image') {
                        setViewing(true);
                    } else {
                        window.location.assign(downloadUrl(attachment));
                    }
                }}
            >
                <span
                    className={cn(
                        'flex h-12 w-10 shrink-0 flex-col items-center justify-center rounded-md',
                        badge.className,
                    )}
                >
                    <FileText className="size-5" aria-hidden />
                    <span className="text-[0.55rem] font-bold tracking-wide">
                        {badge.label}
                    </span>
                </span>
                <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium text-ink">
                        {attachment.name}
                    </span>
                    <span className="block text-xs text-ink-soft">
                        {formatBytes(attachment.size)} · {hint}
                    </span>
                    {details && (
                        <span className="mt-0.5 block truncate text-xs text-ink-soft">
                            {details}
                        </span>
                    )}
                    {readable && reading && reading.pages > 1 && (
                        <span
                            className="mt-1.5 block h-1 max-w-48 overflow-hidden rounded-full bg-paper-edge"
                            aria-hidden
                        >
                            <span
                                className="block h-full bg-primary"
                                style={{
                                    width: `${Math.round((reading.page / reading.pages) * 100)}%`,
                                }}
                            />
                        </span>
                    )}
                </span>
            </button>
            <div className="flex items-center gap-1">
                <Button variant="ghost" size="icon" className="size-10" asChild>
                    <a
                        href={downloadUrl(attachment)}
                        aria-label={`Baixar ${attachment.name}`}
                    >
                        <Download />
                    </a>
                </Button>
                {actions}
            </div>
            {kind === 'image' && (
                <ImageViewerDialog
                    attachment={attachment}
                    alt=""
                    open={viewing}
                    onOpenChange={setViewing}
                />
            )}
            {readable && (
                <Dialog
                    open={viewing}
                    onOpenChange={(open) => {
                        setViewing(open);

                        if (!open) {
                            flush();
                        }
                    }}
                >
                    <DialogContent className="flex h-dvh max-h-dvh w-screen max-w-none flex-col gap-0 overflow-hidden rounded-none border-0 p-0 sm:h-[94dvh] sm:w-[min(72rem,96vw)] sm:max-w-[min(72rem,96vw)] sm:rounded-lg sm:border [&>button:last-child]:hidden">
                        {viewing && (
                            <Suspense
                                fallback={
                                    <div className="flex flex-1 items-center justify-center gap-2 text-sm text-muted-foreground">
                                        <DialogTitle className="sr-only">
                                            {attachment.name}
                                        </DialogTitle>
                                        <DialogDescription className="sr-only">
                                            Abrindo o arquivo
                                        </DialogDescription>
                                        <Spinner />
                                        Abrindo o arquivo…
                                    </div>
                                }
                            >
                                <DocumentReader
                                    attachment={attachment}
                                    initialPage={reading?.page ?? 1}
                                    onPage={report}
                                />
                            </Suspense>
                        )}
                    </DialogContent>
                </Dialog>
            )}
        </div>
    );
}

/**
 * An image in its full size, fitted to the screen.
 */
export function ImageViewerDialog({
    attachment,
    alt,
    open,
    onOpenChange,
}: {
    attachment: KnowledgeAttachment;
    alt: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[96dvh] max-w-[96vw] flex-col gap-3 border-0 bg-neutral-950 p-3 text-white sm:max-w-[96vw] [&>button:last-child]:hidden">
                <div className="flex items-center gap-2">
                    <DialogTitle className="min-w-0 flex-1 truncate text-sm font-normal text-white/80">
                        {alt || attachment.name}
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        Imagem em tamanho maior
                    </DialogDescription>
                    <Button
                        variant="ghost"
                        className="h-10 text-white hover:bg-white/10 hover:text-white"
                        asChild
                    >
                        <a
                            href={attachment.url}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <ExternalLink />
                            Original
                        </a>
                    </Button>
                    <DialogClose asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-10 text-white hover:bg-white/10 hover:text-white"
                            aria-label="Fechar"
                        >
                            <X />
                        </Button>
                    </DialogClose>
                </div>
                <img
                    src={attachment.url}
                    alt={alt}
                    className="mx-auto max-h-[calc(96dvh-5rem)] w-auto max-w-full object-contain"
                />
            </DialogContent>
        </Dialog>
    );
}

/**
 * An image block of the text: keeps its aspect ratio before loading and
 * opens the viewer when tapped.
 */
export function KnowledgeFigure({
    attachment,
    alt,
    actions,
}: {
    attachment: KnowledgeAttachment;
    alt: string;
    actions?: ReactNode;
}) {
    const [viewing, setViewing] = useState(false);

    return (
        <figure className="not-prose relative" data-node-card>
            <button
                type="button"
                className="block w-full overflow-hidden rounded-lg outline-none focus-visible:ring-[3px] focus-visible:ring-ring"
                onClick={() => setViewing(true)}
                aria-label={`Ampliar imagem${alt ? `: ${alt}` : ''}`}
            >
                <img
                    src={attachment.url}
                    alt={alt}
                    width={attachment.width ?? undefined}
                    height={attachment.height ?? undefined}
                    loading="lazy"
                    decoding="async"
                    className="mx-auto h-auto max-h-[75vh] w-auto max-w-full rounded-lg bg-paper-edge object-contain"
                />
            </button>
            {alt && (
                <figcaption className="mt-2 text-center font-sans text-sm text-ink-soft">
                    {alt}
                </figcaption>
            )}
            {actions && (
                <div className="absolute top-2 right-2 flex gap-1 rounded-lg bg-background/90 p-1 shadow">
                    {actions}
                </div>
            )}
            <ImageViewerDialog
                attachment={attachment}
                alt={alt}
                open={viewing}
                onOpenChange={setViewing}
            />
        </figure>
    );
}

/**
 * Placeholder for an attachment whose file is gone.
 */
export function MissingAttachment({ kind }: { kind: 'image' | 'file' }) {
    return (
        <div
            data-node-card
            className="not-prose rounded-xl border border-dashed border-paper-edge p-4 text-center font-sans text-sm text-ink-soft"
        >
            {kind === 'image' ? 'Imagem' : 'Arquivo'} indisponível — ele pode
            ter sido removido.
        </div>
    );
}
