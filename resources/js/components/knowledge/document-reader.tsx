import {
    ChevronLeft,
    ChevronRight,
    Download,
    Minus,
    Plus,
    TriangleAlert,
    X,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DialogClose,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { formatBytes, safeHref } from '@/lib/knowledge';
import type { KnowledgeAttachment } from '@/types';

/**
 * The built-in reader for PDFs (pdf.js), PowerPoint (pptx-preview) and Word
 * (docx-preview). Loaded on demand: the libraries only reach the browser when
 * a file is opened. Every format is shown as a vertical list of pages; the
 * page in view is reported so the reader can resume there next time.
 */

const ZOOMS = [0.5, 0.75, 1, 1.25, 1.5, 2, 3];

/** Width the slides and Word pages are laid out at before fitting. */
const LAYOUT_WIDTH = 960;

type Mounted = {
    pages: HTMLElement[];
    /** Fit the pages to the available width, times the zoom. */
    layout: (width: number, zoom: number) => void;
    destroy: () => void;
};

type Engine = (
    host: HTMLElement,
    attachment: KnowledgeAttachment,
    scroller: HTMLElement,
    signal: AbortSignal,
) => Promise<Mounted>;

async function download(url: string, signal: AbortSignal): Promise<Blob> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        signal,
    });

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }

    return response.blob();
}

/**
 * Links inside a rendered file come from the file: keep only web and e-mail
 * addresses, opened in a new tab.
 */
function sanitizeLinks(root: HTMLElement): void {
    root.querySelectorAll('a').forEach((link) => {
        const href = safeHref(link.getAttribute('href'));

        if (href === null) {
            link.removeAttribute('href');
        } else {
            link.setAttribute('target', '_blank');
            link.setAttribute('rel', 'noopener noreferrer');
        }
    });
}

/** Scale a fixed-width layout to the available width with CSS zoom. */
function zoomLayout(content: HTMLElement, natural: number) {
    return (width: number, zoom: number) => {
        content.style.zoom = String(Math.min(1, width / natural) * zoom || 1);
    };
}

const pdfEngine: Engine = async (host, attachment, scroller, signal) => {
    const [pdfjs, { default: PdfWorker }] = await Promise.all([
        import('pdfjs-dist'),
        // Bundled by Vite as a .js worker (a .mjs file may be served
        // without a JavaScript MIME type by the host). One for the visit.
        import('pdfjs-dist/build/pdf.worker.min.mjs?worker'),
    ]);
    pdfjs.GlobalWorkerOptions.workerPort ??= new PdfWorker();

    const task = pdfjs.getDocument({
        url: attachment.url,
        withCredentials: true,
    });
    signal.addEventListener('abort', () => void task.destroy());

    const doc = await task.promise;
    const first = (await doc.getPage(1)).getViewport({ scale: 1 });

    const pages = Array.from({ length: doc.numPages }, (_, index) => {
        const page = document.createElement('div');
        page.className = 'knowledge-reader-page';
        page.dataset.index = String(index);
        page.style.aspectRatio = `${first.width} / ${first.height}`;
        host.append(page);

        return page;
    });

    // Only pages near the screen keep a canvas, so long PDFs stay light.
    const rendered = new Map<number, { width: number; cancel: () => void }>();
    const near = new Set<number>();

    async function draw(index: number) {
        const element = pages[index];
        const width = element.clientWidth;

        if (rendered.get(index)?.width === width) {
            return;
        }

        rendered.get(index)?.cancel();

        let cancelled = false;
        let renderTask: { cancel: () => void } | null = null;
        rendered.set(index, {
            width,
            cancel: () => {
                cancelled = true;
                renderTask?.cancel();
            },
        });

        try {
            const page = await doc.getPage(index + 1);
            const natural = page.getViewport({ scale: 1 });
            element.style.aspectRatio = `${natural.width} / ${natural.height}`;

            // Sharp on retina screens, but under the ~16 MP canvas limit
            // of iOS Safari.
            const pixels = width * (natural.height / natural.width) * width;
            const ratio = Math.min(
                window.devicePixelRatio || 1,
                2,
                Math.sqrt(16_000_000 / Math.max(pixels, 1)),
            );
            const viewport = page.getViewport({
                scale: (width / natural.width) * ratio,
            });

            const canvas = document.createElement('canvas');
            canvas.width = Math.floor(viewport.width);
            canvas.height = Math.floor(viewport.height);

            if (cancelled) {
                return;
            }

            const render = page.render({ canvas, viewport });
            renderTask = render;
            await render.promise;

            if (!cancelled) {
                element.replaceChildren(canvas);
            }
        } catch {
            // Cancelled or a broken page: it stays blank.
            if (!cancelled) {
                rendered.delete(index);
            }
        }
    }

    function release(index: number) {
        rendered.get(index)?.cancel();
        rendered.delete(index);
        pages[index].replaceChildren();
    }

    const observer = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                const index = Number(
                    (entry.target as HTMLElement).dataset.index,
                );

                if (entry.isIntersecting) {
                    near.add(index);
                    void draw(index);
                } else {
                    near.delete(index);
                    release(index);
                }
            }
        },
        { root: scroller, rootMargin: '150% 0px' },
    );
    pages.forEach((page) => observer.observe(page));

    return {
        pages,
        layout: (width, zoom) => {
            const size = `${Math.round(Math.min(width, 1000) * zoom)}px`;
            pages.forEach((page) => (page.style.width = size));
            near.forEach((index) => void draw(index));
        },
        destroy: () => {
            observer.disconnect();
            rendered.forEach(({ cancel }) => cancel());
            void task.destroy();
        },
    };
};

const docxEngine: Engine = async (host, attachment, _scroller, signal) => {
    const [{ renderAsync }, blob] = await Promise.all([
        import('docx-preview'),
        download(attachment.url, signal),
    ]);

    const styles = document.createElement('div');
    const content = document.createElement('div');
    content.className = 'knowledge-reader-docx';
    host.append(styles, content);

    await renderAsync(blob, content, styles, {
        className: 'docx',
        inWrapper: true,
        breakPages: true,
        ignoreLastRenderedPageBreak: false,
        renderChanges: false,
        renderComments: false,
        useBase64URL: true,
        experimental: false,
    });
    sanitizeLinks(content);

    const pages = [...content.querySelectorAll<HTMLElement>('section.docx')];
    const natural = Math.max(
        ...pages.map((page) => page.offsetWidth),
        LAYOUT_WIDTH,
    );

    return {
        pages,
        layout: zoomLayout(content, natural + 32),
        destroy: () => host.replaceChildren(),
    };
};

const pptxEngine: Engine = async (host, attachment, _scroller, signal) => {
    const [{ init }, blob] = await Promise.all([
        import('pptx-preview'),
        download(attachment.url, signal),
    ]);

    const content = document.createElement('div');
    content.className = 'knowledge-reader-slides';
    host.append(content);

    const previewer = init(content, {
        width: LAYOUT_WIDTH,
        height: (LAYOUT_WIDTH * 9) / 16,
        mode: 'list',
    });
    await previewer.preview(await blob.arrayBuffer());
    sanitizeLinks(content);

    const pages = [
        ...content.querySelectorAll<HTMLElement>('.pptx-preview-slide-wrapper'),
    ];

    return {
        pages,
        layout: zoomLayout(content, LAYOUT_WIDTH + 32),
        destroy: () => {
            previewer.destroy();
            host.replaceChildren();
        },
    };
};

const ENGINES: Partial<Record<KnowledgeAttachment['kind'], Engine>> = {
    pdf: pdfEngine,
    document: docxEngine,
    slides: pptxEngine,
};

/** Page in view: the last one whose top passed the upper third. */
function pageInView(scroller: HTMLElement, pages: HTMLElement[]): number {
    const top = scroller.getBoundingClientRect().top;
    const line = top + scroller.clientHeight / 3;
    let current = 0;

    for (let index = 0; index < pages.length; index++) {
        if (pages[index].getBoundingClientRect().top > line) {
            break;
        }

        current = index;
    }

    // At the very end, the last page counts as read even if it is short.
    if (
        scroller.scrollTop + scroller.clientHeight >=
        scroller.scrollHeight - 4
    ) {
        current = pages.length - 1;
    }

    return current + 1;
}

export default function DocumentReader({
    attachment,
    initialPage,
    onPage,
}: {
    attachment: KnowledgeAttachment;
    /** 1-based; the page to open at. */
    initialPage: number;
    onPage: (page: number, pages: number) => void;
}) {
    const scrollerRef = useRef<HTMLDivElement>(null);
    const hostRef = useRef<HTMLDivElement>(null);
    const mountedRef = useRef<Mounted | null>(null);
    const [status, setStatus] = useState<'loading' | 'ready' | 'error'>(
        'loading',
    );
    const [total, setTotal] = useState(0);
    const [page, setPage] = useState(initialPage);
    const [zoom, setZoom] = useState(1);
    const [typed, setTyped] = useState<string | null>(null);
    const onPageRef = useRef(onPage);
    const zoomRef = useRef(zoom);

    useEffect(() => {
        onPageRef.current = onPage;
        zoomRef.current = zoom;
    });

    function scrollToPage(target: number) {
        const scroller = scrollerRef.current;
        const pages = mountedRef.current?.pages ?? [];
        const element = pages[Math.min(Math.max(target, 1), pages.length) - 1];

        if (!scroller || !element) {
            return;
        }

        scroller.scrollTop +=
            element.getBoundingClientRect().top -
            scroller.getBoundingClientRect().top -
            12;
    }

    function availableWidth(): number {
        return Math.max((scrollerRef.current?.clientWidth ?? 600) - 24, 200);
    }

    // Open the file once.
    useEffect(() => {
        const scroller = scrollerRef.current;
        const host = hostRef.current;
        const engine = ENGINES[attachment.kind];
        const controller = new AbortController();

        if (!scroller || !host || !engine) {
            setStatus('error');

            return;
        }

        let frame = 0;
        let lastReported = 0;

        const onScroll = () => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => {
                const pages = mountedRef.current?.pages ?? [];

                if (pages.length === 0) {
                    return;
                }

                const current = pageInView(scroller, pages);
                setPage(current);

                if (current !== lastReported) {
                    lastReported = current;
                    onPageRef.current(current, pages.length);
                }
            });
        };

        engine(host, attachment, scroller, controller.signal)
            .then((mounted) => {
                if (controller.signal.aborted) {
                    mounted.destroy();

                    return;
                }

                if (mounted.pages.length === 0) {
                    throw new Error('No pages');
                }

                mountedRef.current = mounted;
                mounted.layout(availableWidth(), zoomRef.current);
                setTotal(mounted.pages.length);
                setStatus('ready');

                const start = Math.min(initialPage, mounted.pages.length);
                lastReported = start;
                setPage(start);
                requestAnimationFrame(() => {
                    scrollToPage(start);
                    scroller.addEventListener('scroll', onScroll, {
                        passive: true,
                    });
                });
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setStatus('error');
                }
            });

        const resize = new ResizeObserver(() => {
            const mounted = mountedRef.current;

            if (mounted) {
                const current = pageInView(scroller, mounted.pages);
                mounted.layout(availableWidth(), zoomRef.current);
                scrollToPage(current);
            }
        });
        resize.observe(scroller);

        return () => {
            controller.abort();
            cancelAnimationFrame(frame);
            resize.disconnect();
            scroller.removeEventListener('scroll', onScroll);
            mountedRef.current?.destroy();
            mountedRef.current = null;
        };
        // The reader is mounted per opened file.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [attachment.id]);

    function changeZoom(next: number) {
        const mounted = mountedRef.current;
        setZoom(next);

        if (mounted) {
            const current = page;
            mounted.layout(availableWidth(), next);
            requestAnimationFrame(() => scrollToPage(current));
        }
    }

    function go(target: number) {
        const next = Math.min(Math.max(target, 1), total);
        // Shown at once, so quick repeated presses keep counting.
        setPage(next);
        scrollToPage(next);
    }

    function submitPage(event: FormEvent) {
        event.preventDefault();
        const target = Number(typed);

        if (Number.isInteger(target)) {
            go(target);
        }

        setTyped(null);
    }

    // Arrow keys turn pages (when not typing a page number).
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (
                status !== 'ready' ||
                (event.target as HTMLElement | null)?.tagName === 'INPUT'
            ) {
                return;
            }

            if (event.key === 'ArrowRight') {
                event.preventDefault();
                go(page + 1);
            } else if (event.key === 'ArrowLeft') {
                event.preventDefault();
                go(page - 1);
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    });

    const zoomIndex = ZOOMS.indexOf(zoom);
    const unit = attachment.kind === 'slides' ? 'Slide' : 'Página';

    return (
        <>
            <div className="flex flex-wrap items-center gap-1 border-b px-2 py-2 sm:gap-2 sm:px-3">
                <div className="min-w-0 flex-1 basis-40 px-1">
                    <DialogTitle className="truncate text-sm font-semibold sm:text-base">
                        {attachment.name}
                    </DialogTitle>
                    <DialogDescription className="text-xs">
                        {formatBytes(attachment.size)}
                        {status === 'ready' && ' · continua de onde você parou'}
                    </DialogDescription>
                </div>
                {status === 'ready' && (
                    <div className="flex items-center gap-1">
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-10"
                            aria-label={`${unit} anterior`}
                            disabled={page <= 1}
                            onClick={() => go(page - 1)}
                        >
                            <ChevronLeft />
                        </Button>
                        <form
                            onSubmit={submitPage}
                            className="flex items-center gap-1 text-sm tabular-nums"
                        >
                            <input
                                inputMode="numeric"
                                aria-label={`${unit} atual`}
                                value={typed ?? String(page)}
                                onFocus={(event) => {
                                    setTyped(String(page));
                                    event.target.select();
                                }}
                                onChange={(event) =>
                                    setTyped(
                                        event.target.value.replace(/\D/g, ''),
                                    )
                                }
                                onBlur={() => setTyped(null)}
                                className="h-9 w-12 rounded-md border bg-background text-center outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            />
                            <span className="text-muted-foreground">
                                / {total}
                            </span>
                        </form>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-10"
                            aria-label={`Próxima ${unit.toLowerCase()}`}
                            disabled={page >= total}
                            onClick={() => go(page + 1)}
                        >
                            <ChevronRight />
                        </Button>
                        <span className="mx-1 hidden h-6 w-px bg-border sm:block" />
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-10"
                            aria-label="Diminuir zoom"
                            disabled={zoomIndex <= 0}
                            onClick={() => changeZoom(ZOOMS[zoomIndex - 1])}
                        >
                            <Minus />
                        </Button>
                        <span className="hidden w-11 text-center text-xs text-muted-foreground tabular-nums sm:block">
                            {Math.round(zoom * 100)}%
                        </span>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-10"
                            aria-label="Aumentar zoom"
                            disabled={zoomIndex >= ZOOMS.length - 1}
                            onClick={() => changeZoom(ZOOMS[zoomIndex + 1])}
                        >
                            <Plus />
                        </Button>
                    </div>
                )}
                <div className="flex items-center gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-10"
                        asChild
                    >
                        <a
                            href={`${attachment.url}?download=1`}
                            aria-label={`Baixar ${attachment.name}`}
                            title="Baixar"
                        >
                            <Download />
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
            </div>
            <div
                ref={scrollerRef}
                className="relative min-h-0 flex-1 overflow-auto overscroll-contain bg-muted"
            >
                <div ref={hostRef} className="knowledge-reader py-3" />
                {status === 'loading' && (
                    <div className="absolute inset-0 flex items-center justify-center gap-2 text-sm text-muted-foreground">
                        <Spinner />
                        Abrindo o arquivo…
                    </div>
                )}
                {status === 'error' && (
                    <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 p-6 text-center">
                        <TriangleAlert
                            className="size-8 text-muted-foreground"
                            aria-hidden
                        />
                        <p className="max-w-sm text-sm text-muted-foreground">
                            Não foi possível abrir este arquivo aqui. Baixe para
                            abrir em outro aplicativo.
                        </p>
                        <Button variant="outline" className="h-10" asChild>
                            <a href={`${attachment.url}?download=1`}>
                                <Download />
                                Baixar
                            </a>
                        </Button>
                    </div>
                )}
            </div>
        </>
    );
}
