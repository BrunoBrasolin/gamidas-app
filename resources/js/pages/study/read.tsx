import { Head, Link, router } from '@inertiajs/react';
import {
    Bookmark,
    ChevronLeft,
    ChevronRight,
    ListTree,
    Minus,
    Moon,
    Pencil,
    Plus,
    Sun,
    SunMoon,
    Type,
} from 'lucide-react';
import type { CSSProperties } from 'react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { ContentRenderer } from '@/components/study/content-renderer';
import { StatusControl, StatusIcon } from '@/components/study/status-control';
import { StudyTopBar } from '@/components/study/top-bar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useAppearance } from '@/hooks/use-appearance';
import type { Appearance } from '@/hooks/use-appearance';
import { jsonRequest } from '@/lib/http';
import { collectHeadings, formatRelative, preferences } from '@/lib/study';
import type { StudyHeading } from '@/lib/study';
import { cn } from '@/lib/utils';
import study from '@/routes/study';
import type {
    StudyAttachmentMap,
    StudyChapter,
    StudyPageDetail,
    StudyPageLink,
    StudyPageSummary,
    StudySubject,
} from '@/types';

type Width = 'narrow' | 'medium' | 'wide';

const FONT_SIZES = [1, 1.0625, 1.1875, 1.3125, 1.4375, 1.625];
const MEASURES: Record<Width, string> = {
    narrow: '34rem',
    medium: '42rem',
    wide: '52rem',
};
const PREFS_KEY = 'study.reader';

type ReaderPrefs = { size: number; width: Width };

function useReaderPrefs() {
    const [prefs, setPrefs] = useState<ReaderPrefs>(() =>
        preferences.get<ReaderPrefs>(PREFS_KEY, { size: 2, width: 'medium' }),
    );

    function update(patch: Partial<ReaderPrefs>) {
        setPrefs((current) => {
            const next = { ...current, ...patch };
            preferences.set(PREFS_KEY, next);

            return next;
        });
    }

    const size = Math.min(FONT_SIZES.length - 1, Math.max(0, prefs.size));

    return { size, width: prefs.width in MEASURES ? prefs.width : 'medium', update };
}

/**
 * Saves where the reader was left (per mille of the scrollable height) and
 * restores it when the page opens again.
 */
function useReadingPosition(pageId: number, initial: number) {
    const last = useRef(initial);

    useEffect(() => {
        const scrollable = () =>
            document.documentElement.scrollHeight - window.innerHeight;

        // Restore after Inertia's own scroll reset on navigation.
        const restore =
            initial > 0 && initial < 1000
                ? window.setTimeout(
                      () =>
                          window.scrollTo({
                              top: (scrollable() * initial) / 1000,
                          }),
                      150,
                  )
                : undefined;

        let timer: number | undefined;
        // Measured while scrolling, so leaving the page (when the scroll may
        // already be reset) sends where the reader really was.
        let position = last.current;

        const send = () => {
            if (Math.abs(position - last.current) < 10) {
                return;
            }

            last.current = position;
            jsonRequest('PUT', study.pages.reading.url(pageId), {
                position,
            }).catch(() => {
                // Losing a reading position is harmless.
            });
        };

        const onScroll = () => {
            const max = scrollable();
            position = max <= 0 ? 0 : Math.round((window.scrollY / max) * 1000);
            window.clearTimeout(timer);
            timer = window.setTimeout(send, 1500);
        };

        const onHide = () => {
            if (document.visibilityState === 'hidden') {
                send();
            }
        };

        window.addEventListener('scroll', onScroll, { passive: true });
        document.addEventListener('visibilitychange', onHide);

        return () => {
            window.clearTimeout(restore);
            window.clearTimeout(timer);
            window.removeEventListener('scroll', onScroll);
            document.removeEventListener('visibilitychange', onHide);
            send();
        };
    }, [pageId, initial]);
}

function HeadingList({
    headings,
    onNavigate,
}: {
    headings: StudyHeading[];
    onNavigate?: () => void;
}) {
    return (
        <ul className="space-y-0.5">
            {headings.map((heading) => (
                <li key={heading.id}>
                    <a
                        href={`#${heading.id}`}
                        onClick={onNavigate}
                        className={cn(
                            'block rounded-md py-2 pr-2 text-sm leading-snug text-ink-soft hover:bg-paper-edge/60 hover:text-ink',
                            heading.level === 1 && 'pl-2 font-medium text-ink',
                            heading.level === 2 && 'pl-5',
                            heading.level === 3 && 'pl-8',
                        )}
                    >
                        {heading.text}
                    </a>
                </li>
            ))}
        </ul>
    );
}

function ContentsSheet({
    current,
    headings,
    chapters,
    toc,
}: {
    current: number;
    headings: StudyHeading[];
    chapters: StudyChapter[];
    toc: StudyPageSummary[];
}) {
    const [open, setOpen] = useState(false);
    const title = (id: number | null) =>
        chapters.find((chapter) => chapter.id === id)?.title ?? 'Sem capítulo';
    const hasChapters = chapters.length > 0;

    return (
        <Sheet open={open} onOpenChange={setOpen}>
            <SheetTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-10"
                    aria-label="Sumário"
                >
                    <ListTree />
                </Button>
            </SheetTrigger>
            <SheetContent
                side="left"
                className="w-[min(22rem,88vw)] gap-0 overflow-y-auto bg-paper p-0 text-ink"
            >
                <div className="space-y-6 p-4 pt-[max(1rem,env(safe-area-inset-top))]">
                    <div>
                        <SheetTitle className="font-serif text-lg">
                            Sumário
                        </SheetTitle>
                        <SheetDescription className="text-ink-soft">
                            Seções desta página e as outras páginas da
                            matéria.
                        </SheetDescription>
                    </div>
                    {headings.length > 0 && (
                        <nav aria-label="Nesta página" className="space-y-2">
                            <p className="text-xs font-semibold tracking-wide text-ink-soft uppercase">
                                Nesta página
                            </p>
                            <HeadingList
                                headings={headings}
                                onNavigate={() => setOpen(false)}
                            />
                        </nav>
                    )}
                    <nav aria-label="Páginas da matéria" className="space-y-2">
                        <p className="text-xs font-semibold tracking-wide text-ink-soft uppercase">
                            Páginas
                        </p>
                        <ul className="space-y-0.5">
                            {toc.map((page, index) => (
                                <li key={page.id}>
                                    {hasChapters &&
                                        page.chapter_id !==
                                            toc[index - 1]?.chapter_id && (
                                            <p className="mt-3 mb-1 px-2 text-xs font-medium text-ink-soft">
                                                {title(page.chapter_id)}
                                            </p>
                                        )}
                                    <Link
                                        href={study.pages.show(page.id)}
                                        aria-current={
                                            page.id === current
                                                ? 'page'
                                                : undefined
                                        }
                                        className={cn(
                                            'flex min-h-11 items-center gap-2 rounded-md px-2 font-serif hover:bg-paper-edge/60',
                                            page.id === current &&
                                                'bg-paper-edge font-semibold',
                                        )}
                                    >
                                        <StatusIcon status={page.status} />
                                        <span className="truncate">
                                            {page.title}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </nav>
                </div>
            </SheetContent>
        </Sheet>
    );
}

function ReadingSettings({
    size,
    width,
    update,
}: ReturnType<typeof useReaderPrefs>) {
    const { appearance, updateAppearance } = useAppearance();

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-10"
                    aria-label="Ajustes de leitura"
                >
                    <Type />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-72 space-y-1 p-3">
                <DropdownMenuLabel className="px-0">
                    Tamanho do texto
                </DropdownMenuLabel>
                <div className="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="icon"
                        className="size-11"
                        aria-label="Diminuir texto"
                        disabled={size === 0}
                        onClick={() => update({ size: size - 1 })}
                    >
                        <Minus />
                    </Button>
                    <div
                        className="flex-1 text-center font-serif"
                        style={{ fontSize: `${FONT_SIZES[size]}rem` }}
                        aria-live="polite"
                    >
                        Aa
                    </div>
                    <Button
                        variant="outline"
                        size="icon"
                        className="size-11"
                        aria-label="Aumentar texto"
                        disabled={size === FONT_SIZES.length - 1}
                        onClick={() => update({ size: size + 1 })}
                    >
                        <Plus />
                    </Button>
                </div>
                <DropdownMenuSeparator className="my-3" />
                <DropdownMenuLabel className="px-0">
                    Largura da coluna
                </DropdownMenuLabel>
                <ToggleGroup
                    type="single"
                    variant="outline"
                    className="w-full"
                    value={width}
                    onValueChange={(value) =>
                        value && update({ width: value as Width })
                    }
                >
                    <ToggleGroupItem value="narrow" className="h-11 flex-1">
                        Estreita
                    </ToggleGroupItem>
                    <ToggleGroupItem value="medium" className="h-11 flex-1">
                        Média
                    </ToggleGroupItem>
                    <ToggleGroupItem value="wide" className="h-11 flex-1">
                        Larga
                    </ToggleGroupItem>
                </ToggleGroup>
                <DropdownMenuSeparator className="my-3" />
                <DropdownMenuLabel className="px-0">Tema</DropdownMenuLabel>
                <ToggleGroup
                    type="single"
                    variant="outline"
                    className="w-full"
                    value={appearance}
                    onValueChange={(value) =>
                        value && updateAppearance(value as Appearance)
                    }
                >
                    <ToggleGroupItem
                        value="light"
                        className="h-11 flex-1"
                        aria-label="Claro"
                    >
                        <Sun />
                    </ToggleGroupItem>
                    <ToggleGroupItem
                        value="dark"
                        className="h-11 flex-1"
                        aria-label="Escuro"
                    >
                        <Moon />
                    </ToggleGroupItem>
                    <ToggleGroupItem
                        value="system"
                        className="h-11 flex-1"
                        aria-label="Como o sistema"
                    >
                        <SunMoon />
                    </ToggleGroupItem>
                </ToggleGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function NeighborLink({
    page,
    direction,
}: {
    page: StudyPageLink;
    direction: 'previous' | 'next';
}) {
    const next = direction === 'next';

    return (
        <Link
            href={study.pages.show(page.id)}
            className={cn(
                'flex min-h-16 flex-1 items-center gap-3 rounded-xl border border-paper-edge bg-background/50 p-4 outline-none hover:bg-background focus-visible:ring-[3px] focus-visible:ring-ring',
                next && 'flex-row-reverse text-right',
            )}
        >
            {next ? (
                <ChevronRight className="size-5 shrink-0 text-ink-soft" />
            ) : (
                <ChevronLeft className="size-5 shrink-0 text-ink-soft" />
            )}
            <span className="min-w-0">
                <span className="block text-xs text-ink-soft">
                    {next ? 'Próxima' : 'Anterior'}
                </span>
                <span className="block truncate font-serif font-semibold">
                    {page.title}
                </span>
            </span>
        </Link>
    );
}

export default function StudyRead({
    subject,
    page,
    attachments,
    previous,
    next,
    chapters,
    toc,
}: {
    subject: StudySubject;
    page: StudyPageDetail & { reading_position: number };
    attachments: StudyAttachmentMap;
    previous: StudyPageLink | null;
    next: StudyPageLink | null;
    chapters: StudyChapter[];
    toc: StudyPageSummary[];
}) {
    const prefs = useReaderPrefs();
    const { headings, ids } = useMemo(
        () => collectHeadings(page.content),
        [page.content],
    );

    useReadingPosition(page.id, page.reading_position);

    // Arrow keys on an external keyboard turn pages.
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;

            if (
                event.altKey ||
                event.metaKey ||
                event.ctrlKey ||
                target?.closest('input, textarea, select, [contenteditable]')
            ) {
                return;
            }

            const destination =
                event.key === 'ArrowRight'
                    ? next
                    : event.key === 'ArrowLeft'
                      ? previous
                      : null;

            if (destination) {
                router.visit(study.pages.show.url(destination.id));
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [next, previous]);

    const isEmpty =
        (page.content.content ?? []).every(
            (node) => node.type === 'paragraph' && !node.content?.length,
        );

    return (
        <>
            <Head title={`${page.title} · ${subject.title}`} />
            <StudyTopBar
                backHref={study.subjects.show.url(subject.id)}
                backLabel={subject.title}
                actions={
                    <>
                        <ContentsSheet
                            current={page.id}
                            headings={headings}
                            chapters={chapters}
                            toc={toc}
                        />
                        <ReadingSettings {...prefs} />
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-10"
                            aria-pressed={page.bookmarked}
                            aria-label={
                                page.bookmarked
                                    ? 'Tirar marcador'
                                    : 'Marcar página'
                            }
                            onClick={() =>
                                page.bookmarked
                                    ? router.delete(
                                          study.pages.unbookmark.url(page.id),
                                          { preserveScroll: true },
                                      )
                                    : router.post(
                                          study.pages.bookmark.url(page.id),
                                          {},
                                          { preserveScroll: true },
                                      )
                            }
                        >
                            <Bookmark
                                className={cn(
                                    page.bookmarked &&
                                        'fill-current text-amber-600',
                                )}
                            />
                        </Button>
                        <StatusControl
                            pageId={page.id}
                            status={page.status}
                            compact
                        />
                        <Button
                            variant="outline"
                            className="ml-1 h-10"
                            asChild
                        >
                            <Link href={study.pages.edit(page.id)}>
                                <Pencil />
                                <span className="hidden sm:inline">
                                    Editar
                                </span>
                            </Link>
                        </Button>
                    </>
                }
            />

            <div className="mx-auto flex max-w-6xl justify-center gap-10 px-5 sm:px-8">
                <main
                    className="w-full min-w-0 pt-10 pb-16 sm:pt-14"
                    style={
                        {
                            maxWidth: MEASURES[prefs.width],
                            fontSize: `${FONT_SIZES[prefs.size]}rem`,
                            '--study-font-size': `${FONT_SIZES[prefs.size]}rem`,
                        } as CSSProperties
                    }
                >
                    <header className="mb-10 space-y-3">
                        {page.chapter && (
                            <p className="text-sm font-medium tracking-wide text-ink-soft uppercase">
                                {page.chapter}
                            </p>
                        )}
                        <h1 className="font-serif text-[2.1em] leading-tight font-semibold tracking-tight text-balance break-words">
                            {page.title}
                        </h1>
                        <p className="text-sm text-ink-soft">
                            Atualizada {formatRelative(page.updated_at)}
                            {page.archived && ' · arquivada'}
                        </p>
                    </header>

                    {isEmpty ? (
                        <div className="rounded-2xl border border-dashed border-paper-edge p-8 text-center">
                            <p className="font-serif text-lg">
                                Esta página ainda está em branco.
                            </p>
                            <Button className="mt-4 h-11" asChild>
                                <Link href={study.pages.edit(page.id)}>
                                    <Pencil />
                                    Começar a escrever
                                </Link>
                            </Button>
                        </div>
                    ) : (
                        <ContentRenderer
                            doc={page.content}
                            attachments={attachments}
                            headingIds={ids}
                        />
                    )}

                    {(previous || next) && (
                        <nav
                            aria-label="Páginas vizinhas"
                            className="mt-16 flex flex-col gap-3 border-t border-paper-edge pt-8 sm:flex-row"
                        >
                            {previous ? (
                                <NeighborLink
                                    page={previous}
                                    direction="previous"
                                />
                            ) : (
                                <span className="hidden flex-1 sm:block" />
                            )}
                            {next && (
                                <NeighborLink page={next} direction="next" />
                            )}
                        </nav>
                    )}
                </main>

                {headings.length > 1 && (
                    <aside
                        aria-label="Nesta página"
                        className="sticky top-20 hidden max-h-[calc(100dvh-6rem)] w-60 shrink-0 self-start overflow-y-auto pt-14 xl:block"
                    >
                        <p className="mb-2 px-2 text-xs font-semibold tracking-wide text-ink-soft uppercase">
                            Nesta página
                        </p>
                        <HeadingList headings={headings} />
                    </aside>
                )}
            </div>
        </>
    );
}
