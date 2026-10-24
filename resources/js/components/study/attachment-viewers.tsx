import { Download, ExternalLink, FileText, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatBytes, isTouchDevice } from '@/lib/study';
import { cn } from '@/lib/utils';
import type { StudyAttachment } from '@/types';

function downloadUrl(attachment: StudyAttachment): string {
    return `${attachment.url}?download=1`;
}

/**
 * A PDF shown in the text as a document card. Tapping it opens the PDF: on
 * touch devices in the system viewer (a new tab), elsewhere in an
 * integrated viewer with the same fallback.
 */
export function PdfCard({
    attachment,
    actions,
    className,
}: {
    attachment: StudyAttachment;
    /** Extra controls (the editor puts replace/remove here). */
    actions?: ReactNode;
    className?: string;
}) {
    const [viewing, setViewing] = useState(false);

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
                    if (isTouchDevice()) {
                        window.open(attachment.url, '_blank', 'noopener');
                    } else {
                        setViewing(true);
                    }
                }}
            >
                <span className="flex h-12 w-10 shrink-0 flex-col items-center justify-center rounded-md bg-red-600/10 text-red-700 dark:text-red-400">
                    <FileText className="size-5" aria-hidden />
                    <span className="text-[0.55rem] font-bold tracking-wide">
                        PDF
                    </span>
                </span>
                <span className="min-w-0">
                    <span className="block truncate text-sm font-medium text-ink">
                        {attachment.name}
                    </span>
                    <span className="block text-xs text-ink-soft">
                        {formatBytes(attachment.size)} · toque para abrir
                    </span>
                </span>
            </button>
            <div className="flex items-center gap-1">
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-10"
                    asChild
                >
                    <a
                        href={downloadUrl(attachment)}
                        aria-label={`Baixar ${attachment.name}`}
                    >
                        <Download />
                    </a>
                </Button>
                {actions}
            </div>
            <PdfViewerDialog
                attachment={attachment}
                open={viewing}
                onOpenChange={setViewing}
            />
        </div>
    );
}

export function PdfViewerDialog({
    attachment,
    open,
    onOpenChange,
}: {
    attachment: StudyAttachment;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="flex h-[92dvh] max-w-[min(64rem,96vw)] flex-col gap-3 p-3 sm:max-w-[min(64rem,96vw)] [&>button:last-child]:hidden"
            >
                <div className="flex flex-wrap items-center gap-2">
                    <div className="min-w-0 flex-1">
                        <DialogTitle className="truncate text-base">
                            {attachment.name}
                        </DialogTitle>
                        <DialogDescription className="text-xs">
                            {formatBytes(attachment.size)} · Se o PDF não
                            aparecer, abra em nova aba.
                        </DialogDescription>
                    </div>
                    <Button variant="outline" className="h-10" asChild>
                        <a
                            href={attachment.url}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <ExternalLink />
                            Abrir em nova aba
                        </a>
                    </Button>
                    <Button variant="outline" className="h-10" asChild>
                        <a href={downloadUrl(attachment)}>
                            <Download />
                            Baixar
                        </a>
                    </Button>
                    <DialogClose asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-10"
                            aria-label="Fechar"
                        >
                            <X />
                        </Button>
                    </DialogClose>
                </div>
                {open && (
                    <iframe
                        src={attachment.url}
                        title={attachment.name}
                        className="min-h-0 w-full flex-1 rounded-md border bg-muted"
                    />
                )}
            </DialogContent>
        </Dialog>
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
    attachment: StudyAttachment;
    alt: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="flex max-h-[96dvh] max-w-[96vw] flex-col gap-3 border-0 bg-neutral-950 p-3 text-white sm:max-w-[96vw] [&>button:last-child]:hidden"
            >
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
export function StudyFigure({
    attachment,
    alt,
    actions,
}: {
    attachment: StudyAttachment;
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
export function MissingAttachment({ kind }: { kind: 'image' | 'pdf' }) {
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
