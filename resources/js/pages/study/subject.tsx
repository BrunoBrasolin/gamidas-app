import { Head, Link, router } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    ArrowDown,
    ArrowUp,
    Bookmark,
    BookmarkMinus,
    BookmarkPlus,
    BookOpen,
    Copy,
    FileText,
    FolderInput,
    FolderPlus,
    MoreHorizontal,
    Paperclip,
    Pencil,
    Plus,
    Search,
    TextCursorInput,
    Trash2,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { ConfirmDialog } from '@/components/finance/confirm-dialog';
import { BookCover } from '@/components/study/book-cover';
import { MovePageDialog } from '@/components/study/move-page-dialog';
import { ProgressBar } from '@/components/study/progress-bar';
import { PromptDialog } from '@/components/study/prompt-dialog';
import {
    setPageStatus,
    StatusIcon,
} from '@/components/study/status-control';
import { SubjectFormDialog } from '@/components/study/subject-form-dialog';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    formatRelative,
    normalize,
    plural,
    STATUS_LABELS,
    STATUS_ORDER,
} from '@/lib/study';
import study from '@/routes/study';
import type {
    StudyChapter,
    StudyDestination,
    StudyPageLink,
    StudyPageStatus,
    StudyPageSummary,
    StudySubject,
} from '@/types';

type Dialog =
    | { type: 'new-chapter' }
    | { type: 'rename-chapter'; chapter: StudyChapter }
    | { type: 'rename-page'; page: StudyPageSummary }
    | { type: 'move-page'; page: StudyPageSummary }
    | { type: 'delete-page'; page: StudyPageSummary }
    | { type: 'delete-chapter'; chapter: StudyChapter }
    | { type: 'edit-subject' }
    | null;

type Group = { chapter: StudyChapter | null; pages: StudyPageSummary[] };

const keep = { preserveScroll: true, preserveState: true };

function createPage(subjectId: number, chapterId: number | null) {
    router.post(study.pages.store.url(subjectId), {
        title: '',
        chapter_id: chapterId,
    });
}

function PageRow({
    page,
    organizing,
    canMoveUp,
    canMoveDown,
    onMove,
    onDialog,
}: {
    page: StudyPageSummary;
    organizing: boolean;
    canMoveUp: boolean;
    canMoveDown: boolean;
    onMove: (delta: -1 | 1) => void;
    onDialog: (dialog: Dialog) => void;
}) {
    return (
        <li className="flex items-center gap-1 rounded-lg pr-1 hover:bg-background/60">
            <Link
                href={study.pages.show(page.id)}
                className="flex min-h-14 min-w-0 flex-1 items-center gap-3 rounded-lg px-3 py-2 outline-none focus-visible:ring-[3px] focus-visible:ring-ring"
            >
                <StatusIcon status={page.status} />
                <span className="min-w-0 flex-1">
                    <span className="block truncate font-serif text-[1.05rem] text-ink">
                        {page.title}
                    </span>
                    <span className="flex items-center gap-2 text-xs text-ink-soft">
                        <span>
                            Editada {formatRelative(page.updated_at)}
                        </span>
                        {page.attachments_count > 0 && (
                            <span className="flex items-center gap-0.5">
                                <Paperclip className="size-3" aria-hidden />
                                {page.attachments_count}
                                <span className="sr-only"> anexos</span>
                            </span>
                        )}
                    </span>
                </span>
                {page.bookmarked && (
                    <Bookmark
                        className="size-4 shrink-0 fill-current text-amber-600"
                        aria-label="Marcada"
                    />
                )}
            </Link>
            {organizing ? (
                <>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-10"
                        disabled={!canMoveUp}
                        aria-label={`Subir ${page.title}`}
                        onClick={() => onMove(-1)}
                    >
                        <ArrowUp />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-10"
                        disabled={!canMoveDown}
                        aria-label={`Descer ${page.title}`}
                        onClick={() => onMove(1)}
                    >
                        <ArrowDown />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-10"
                        aria-label={`Mover ${page.title} para outro capítulo`}
                        onClick={() => onDialog({ type: 'move-page', page })}
                    >
                        <FolderInput />
                    </Button>
                </>
            ) : (
                <>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-10"
                        asChild
                    >
                        <Link
                            href={study.pages.edit(page.id)}
                            aria-label={`Editar ${page.title}`}
                        >
                            <Pencil />
                        </Link>
                    </Button>
                    <PageMenu page={page} onDialog={onDialog} />
                </>
            )}
        </li>
    );
}

function PageMenu({
    page,
    onDialog,
}: {
    page: StudyPageSummary;
    onDialog: (dialog: Dialog) => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-10"
                    aria-label={`Mais ações de ${page.title}`}
                >
                    <MoreHorizontal />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
                <DropdownMenuItem className="min-h-10" asChild>
                    <Link href={study.pages.show(page.id)}>
                        <BookOpen />
                        Ler
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem
                    className="min-h-10"
                    onSelect={() => onDialog({ type: 'rename-page', page })}
                >
                    <TextCursorInput />
                    Renomear
                </DropdownMenuItem>
                <DropdownMenuItem
                    className="min-h-10"
                    onSelect={() =>
                        router.post(study.pages.duplicate.url(page.id))
                    }
                >
                    <Copy />
                    Duplicar
                </DropdownMenuItem>
                <DropdownMenuItem
                    className="min-h-10"
                    onSelect={() => onDialog({ type: 'move-page', page })}
                >
                    <FolderInput />
                    Mover…
                </DropdownMenuItem>
                <DropdownMenuItem
                    className="min-h-10"
                    onSelect={() =>
                        page.bookmarked
                            ? router.delete(
                                  study.pages.unbookmark.url(page.id),
                                  keep,
                              )
                            : router.post(
                                  study.pages.bookmark.url(page.id),
                                  {},
                                  keep,
                              )
                    }
                >
                    {page.bookmarked ? <BookmarkMinus /> : <BookmarkPlus />}
                    {page.bookmarked ? 'Tirar marcador' : 'Marcar página'}
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuLabel className="text-xs text-muted-foreground">
                    Status
                </DropdownMenuLabel>
                <DropdownMenuRadioGroup
                    value={page.status}
                    onValueChange={(value) =>
                        setPageStatus(page.id, value as StudyPageStatus)
                    }
                >
                    {STATUS_ORDER.map((status) => (
                        <DropdownMenuRadioItem
                            key={status}
                            value={status}
                            className="min-h-10"
                        >
                            <StatusIcon status={status} />
                            {STATUS_LABELS[status]}
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    className="min-h-10"
                    onSelect={() =>
                        router.post(study.pages.archive.url(page.id), {}, keep)
                    }
                >
                    <Archive />
                    Arquivar
                </DropdownMenuItem>
                <DropdownMenuItem
                    className="min-h-10"
                    variant="destructive"
                    onSelect={() => onDialog({ type: 'delete-page', page })}
                >
                    <Trash2 />
                    Excluir
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

export default function StudySubjectPage({
    subject,
    chapters,
    pages,
    continue_page: continuePage,
    destinations,
}: {
    subject: StudySubject;
    chapters: StudyChapter[];
    pages: StudyPageSummary[];
    continue_page: StudyPageLink | null;
    destinations: StudyDestination[];
}) {
    const [query, setQuery] = useState('');
    const [organizing, setOrganizing] = useState(false);
    const [dialog, setDialog] = useState<Dialog>(null);

    const live = pages.filter((page) => !page.archived);
    const archived = pages.filter((page) => page.archived);

    // Chapters in order, then the pages without chapter.
    const groups: Group[] = useMemo(() => {
        const byChapter = (id: number | null) =>
            pages
                .filter((page) => !page.archived && page.chapter_id === id)
                .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);

        return [
            ...chapters.map((chapter) => ({
                chapter,
                pages: byChapter(chapter.id),
            })),
            { chapter: null, pages: byChapter(null) },
        ];
    }, [chapters, pages]);

    const needle = normalize(query.trim());
    const visibleGroups =
        needle === ''
            ? groups
            : groups
                  .map((group) => ({
                      ...group,
                      pages: group.pages.filter((page) =>
                          normalize(page.title).includes(needle),
                      ),
                  }))
                  .filter((group) => group.pages.length > 0);

    const firstPage = groups.flatMap((group) => group.pages)[0];

    function save(next: Group[]) {
        router.put(
            study.subjects.arrange.url(subject.id),
            {
                chapters: next
                    .filter((group) => group.chapter)
                    .map((group) => group.chapter!.id),
                pages: next.flatMap((group) =>
                    group.pages.map((page) => ({
                        id: page.id,
                        chapter_id: group.chapter?.id ?? null,
                    })),
                ),
            },
            keep,
        );
    }

    function moveChapter(index: number, delta: -1 | 1) {
        const next = [...groups];
        const [moved] = next.splice(index, 1);
        next.splice(index + delta, 0, moved);
        save(next);
    }

    function movePage(groupIndex: number, pageIndex: number, delta: -1 | 1) {
        const next = groups.map((group) => ({
            ...group,
            pages: [...group.pages],
        }));
        const list = next[groupIndex].pages;
        const [moved] = list.splice(pageIndex, 1);
        list.splice(pageIndex + delta, 0, moved);
        save(next);
    }

    const hasChapters = chapters.length > 0;

    return (
        <>
            <Head title={subject.title} />
            <div className="flex flex-1 flex-col bg-paper text-ink md:rounded-xl">
                <div className="mx-auto w-full max-w-4xl space-y-8 px-4 py-6 sm:px-6 sm:py-8">
                    <header className="flex flex-col gap-5 sm:flex-row sm:items-end">
                        <div className="w-28 shrink-0 sm:w-36">
                            <BookCover
                                title={subject.title}
                                color={subject.accent_color}
                                coverUrl={subject.cover_url}
                                pinned={subject.is_pinned}
                            />
                        </div>
                        <div className="min-w-0 flex-1 space-y-3">
                            <div className="space-y-1">
                                {subject.archived && (
                                    <p className="text-xs font-medium tracking-wide text-ink-soft uppercase">
                                        Arquivada
                                    </p>
                                )}
                                <h1 className="font-serif text-3xl leading-tight font-semibold tracking-tight break-words sm:text-4xl">
                                    {subject.title}
                                </h1>
                                {subject.description && (
                                    <p className="max-w-prose text-ink-soft whitespace-pre-line">
                                        {subject.description}
                                    </p>
                                )}
                            </div>
                            <div className="max-w-sm">
                                <ProgressBar counts={subject.counts} showLabel />
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {(continuePage ?? firstPage) && (
                                    <Button className="h-11 px-5" asChild>
                                        <Link
                                            href={study.pages.show(
                                                (continuePage ?? firstPage)!.id,
                                            )}
                                        >
                                            <BookOpen />
                                            {continuePage
                                                ? 'Continuar lendo'
                                                : 'Começar a ler'}
                                        </Link>
                                    </Button>
                                )}
                                <Button
                                    variant={firstPage ? 'outline' : 'default'}
                                    className="h-11"
                                    onClick={() => createPage(subject.id, null)}
                                >
                                    <Plus />
                                    Nova página
                                </Button>
                                <Button
                                    variant="outline"
                                    className="h-11"
                                    onClick={() =>
                                        setDialog({ type: 'new-chapter' })
                                    }
                                >
                                    <FolderPlus />
                                    Novo capítulo
                                </Button>
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="size-11"
                                            aria-label="Mais ações da matéria"
                                        >
                                            <MoreHorizontal />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        className="w-52"
                                    >
                                        <DropdownMenuItem
                                            className="min-h-10"
                                            onSelect={() =>
                                                setDialog({
                                                    type: 'edit-subject',
                                                })
                                            }
                                        >
                                            <Pencil />
                                            Editar matéria
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            className="min-h-10"
                                            onSelect={() =>
                                                subject.archived
                                                    ? router.delete(
                                                          study.subjects.restore.url(
                                                              subject.id,
                                                          ),
                                                          keep,
                                                      )
                                                    : router.post(
                                                          study.subjects.archive.url(
                                                              subject.id,
                                                          ),
                                                          {},
                                                          keep,
                                                      )
                                            }
                                        >
                                            {subject.archived ? (
                                                <ArchiveRestore />
                                            ) : (
                                                <Archive />
                                            )}
                                            {subject.archived
                                                ? 'Restaurar'
                                                : 'Arquivar'}
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </div>
                    </header>

                    {live.length === 0 && !hasChapters ? (
                        <div className="flex flex-col items-center gap-4 rounded-2xl border border-dashed border-paper-edge px-6 py-14 text-center">
                            <FileText
                                className="size-10 text-ink-soft"
                                aria-hidden
                            />
                            <div className="space-y-1">
                                <p className="font-serif text-lg font-semibold">
                                    Nenhuma página ainda
                                </p>
                                <p className="max-w-md text-sm text-ink-soft">
                                    Escreva a primeira página. Capítulos são
                                    opcionais — crie quando a matéria crescer.
                                </p>
                            </div>
                            <Button
                                className="h-11 px-5"
                                onClick={() => createPage(subject.id, null)}
                            >
                                <Plus />
                                Escrever a primeira página
                            </Button>
                        </div>
                    ) : (
                        <section
                            aria-labelledby="toc-title"
                            className="space-y-4"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <h2
                                    id="toc-title"
                                    className="mr-auto font-serif text-xl font-semibold"
                                >
                                    Sumário
                                </h2>
                                <div className="relative w-full sm:w-64">
                                    <Search
                                        className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-ink-soft"
                                        aria-hidden
                                    />
                                    <Input
                                        type="search"
                                        placeholder="Filtrar páginas"
                                        aria-label="Filtrar páginas pelo título"
                                        className="h-11 bg-background/70 pl-9 text-base"
                                        value={query}
                                        disabled={organizing}
                                        onChange={(event) =>
                                            setQuery(event.target.value)
                                        }
                                    />
                                </div>
                                <Button
                                    variant={organizing ? 'default' : 'outline'}
                                    className="h-11"
                                    aria-pressed={organizing}
                                    onClick={() => {
                                        setOrganizing(!organizing);
                                        setQuery('');
                                    }}
                                >
                                    {organizing ? 'Concluir' : 'Organizar'}
                                </Button>
                            </div>

                            {needle !== '' && (
                                <Button
                                    variant="link"
                                    className="h-auto px-0 text-ink-soft"
                                    asChild
                                >
                                    <Link
                                        href={study.search({
                                            query: {
                                                q: query.trim(),
                                                subject: subject.id,
                                            },
                                        })}
                                    >
                                        Buscar “{query.trim()}” no texto das
                                        páginas
                                    </Link>
                                </Button>
                            )}

                            {visibleGroups.length === 0 && (
                                <p className="text-sm text-ink-soft">
                                    Nenhuma página com esse título.
                                </p>
                            )}

                            <div className="space-y-6">
                                {visibleGroups.map((group, groupIndex) => {
                                    const { chapter } = group;
                                    const showHeader =
                                        chapter !== null || hasChapters;

                                    if (
                                        chapter === null &&
                                        group.pages.length === 0
                                    ) {
                                        return null;
                                    }

                                    return (
                                        <section
                                            key={chapter?.id ?? 'loose'}
                                            aria-label={
                                                chapter?.title ?? 'Sem capítulo'
                                            }
                                            className="space-y-1"
                                        >
                                            {showHeader && (
                                                <div className="flex items-center gap-1 border-b border-paper-edge pb-1">
                                                    <h3 className="min-w-0 flex-1 truncate px-1 font-sans text-sm font-semibold tracking-wide text-ink-soft uppercase">
                                                        {chapter?.title ??
                                                            'Sem capítulo'}
                                                        <span className="ml-2 font-normal normal-case">
                                                            {group.pages.length}
                                                        </span>
                                                    </h3>
                                                    {chapter &&
                                                        (organizing ? (
                                                            <>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="size-10"
                                                                    disabled={
                                                                        groupIndex ===
                                                                        0
                                                                    }
                                                                    aria-label={`Subir capítulo ${chapter.title}`}
                                                                    onClick={() =>
                                                                        moveChapter(
                                                                            groupIndex,
                                                                            -1,
                                                                        )
                                                                    }
                                                                >
                                                                    <ArrowUp />
                                                                </Button>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="size-10"
                                                                    disabled={
                                                                        groupIndex >=
                                                                        chapters.length -
                                                                            1
                                                                    }
                                                                    aria-label={`Descer capítulo ${chapter.title}`}
                                                                    onClick={() =>
                                                                        moveChapter(
                                                                            groupIndex,
                                                                            1,
                                                                        )
                                                                    }
                                                                >
                                                                    <ArrowDown />
                                                                </Button>
                                                            </>
                                                        ) : (
                                                            <>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="size-10"
                                                                    aria-label={`Nova página em ${chapter.title}`}
                                                                    onClick={() =>
                                                                        createPage(
                                                                            subject.id,
                                                                            chapter.id,
                                                                        )
                                                                    }
                                                                >
                                                                    <Plus />
                                                                </Button>
                                                                <DropdownMenu>
                                                                    <DropdownMenuTrigger
                                                                        asChild
                                                                    >
                                                                        <Button
                                                                            variant="ghost"
                                                                            size="icon"
                                                                            className="size-10"
                                                                            aria-label={`Ações do capítulo ${chapter.title}`}
                                                                        >
                                                                            <MoreHorizontal />
                                                                        </Button>
                                                                    </DropdownMenuTrigger>
                                                                    <DropdownMenuContent
                                                                        align="end"
                                                                        className="w-52"
                                                                    >
                                                                        <DropdownMenuItem
                                                                            className="min-h-10"
                                                                            onSelect={() =>
                                                                                setDialog(
                                                                                    {
                                                                                        type: 'rename-chapter',
                                                                                        chapter,
                                                                                    },
                                                                                )
                                                                            }
                                                                        >
                                                                            <TextCursorInput />
                                                                            Renomear
                                                                        </DropdownMenuItem>
                                                                        <DropdownMenuItem
                                                                            className="min-h-10"
                                                                            variant="destructive"
                                                                            onSelect={() =>
                                                                                setDialog(
                                                                                    {
                                                                                        type: 'delete-chapter',
                                                                                        chapter,
                                                                                    },
                                                                                )
                                                                            }
                                                                        >
                                                                            <Trash2 />
                                                                            Excluir
                                                                            capítulo
                                                                        </DropdownMenuItem>
                                                                    </DropdownMenuContent>
                                                                </DropdownMenu>
                                                            </>
                                                        ))}
                                                </div>
                                            )}
                                            {group.pages.length === 0 ? (
                                                <p className="px-3 py-3 text-sm text-ink-soft">
                                                    Nenhuma página neste
                                                    capítulo.
                                                </p>
                                            ) : (
                                                <ul>
                                                    {group.pages.map(
                                                        (page, pageIndex) => (
                                                            <PageRow
                                                                key={page.id}
                                                                page={page}
                                                                organizing={
                                                                    organizing
                                                                }
                                                                canMoveUp={
                                                                    pageIndex >
                                                                    0
                                                                }
                                                                canMoveDown={
                                                                    pageIndex <
                                                                    group.pages
                                                                        .length -
                                                                        1
                                                                }
                                                                onMove={(
                                                                    delta,
                                                                ) =>
                                                                    movePage(
                                                                        groupIndex,
                                                                        pageIndex,
                                                                        delta,
                                                                    )
                                                                }
                                                                onDialog={
                                                                    setDialog
                                                                }
                                                            />
                                                        ),
                                                    )}
                                                </ul>
                                            )}
                                        </section>
                                    );
                                })}
                            </div>
                        </section>
                    )}

                    {archived.length > 0 && (
                        <Collapsible>
                            <CollapsibleTrigger asChild>
                                <Button
                                    variant="ghost"
                                    className="h-10 px-2 text-ink-soft"
                                >
                                    <Archive />
                                    {plural(
                                        archived.length,
                                        'página arquivada',
                                        'páginas arquivadas',
                                    )}
                                </Button>
                            </CollapsibleTrigger>
                            <CollapsibleContent>
                                <ul className="mt-2 divide-y divide-paper-edge rounded-xl border border-paper-edge bg-background/60">
                                    {archived.map((page) => (
                                        <li
                                            key={page.id}
                                            className="flex items-center gap-2 p-2 pl-4"
                                        >
                                            <Link
                                                href={study.pages.show(page.id)}
                                                className="min-w-0 flex-1 truncate font-serif hover:underline"
                                            >
                                                {page.title}
                                            </Link>
                                            <Button
                                                variant="outline"
                                                className="h-10"
                                                onClick={() =>
                                                    router.delete(
                                                        study.pages.restore.url(
                                                            page.id,
                                                        ),
                                                        keep,
                                                    )
                                                }
                                            >
                                                <ArchiveRestore />
                                                Restaurar
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-10 text-destructive"
                                                aria-label={`Excluir ${page.title}`}
                                                onClick={() =>
                                                    setDialog({
                                                        type: 'delete-page',
                                                        page,
                                                    })
                                                }
                                            >
                                                <Trash2 />
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            </CollapsibleContent>
                        </Collapsible>
                    )}

                    <div className="border-t border-paper-edge pt-6">
                        <ConfirmDialog
                            trigger={
                                <Button
                                    variant="ghost"
                                    className="h-10 text-destructive hover:text-destructive"
                                >
                                    <Trash2 />
                                    Excluir matéria
                                </Button>
                            }
                            title="Excluir matéria?"
                            description={`“${subject.title}” será excluída com ${plural(pages.length, 'página', 'páginas')}, imagens e PDFs. Não dá para desfazer — se quiser só tirar da estante, arquive.`}
                            confirmLabel="Excluir"
                            url={study.subjects.destroy.url(subject.id)}
                        />
                    </div>
                </div>
            </div>

            <PromptDialog
                open={dialog?.type === 'new-chapter'}
                onOpenChange={(open) => !open && setDialog(null)}
                title="Novo capítulo"
                description="Capítulos agrupam páginas. Páginas sem capítulo continuam no fim do sumário."
                label="Título do capítulo"
                submitLabel="Criar"
                method="post"
                url={study.chapters.store.url(subject.id)}
            />
            {dialog?.type === 'rename-chapter' && (
                <PromptDialog
                    open
                    onOpenChange={(open) => !open && setDialog(null)}
                    title="Renomear capítulo"
                    label="Título do capítulo"
                    initialValue={dialog.chapter.title}
                    method="put"
                    url={study.chapters.update.url(dialog.chapter.id)}
                />
            )}
            {dialog?.type === 'rename-page' && (
                <PromptDialog
                    open
                    onOpenChange={(open) => !open && setDialog(null)}
                    title="Renomear página"
                    label="Título da página"
                    initialValue={dialog.page.title}
                    maxLength={200}
                    method="put"
                    url={study.pages.update.url(dialog.page.id)}
                />
            )}
            {dialog?.type === 'move-page' && (
                <MovePageDialog
                    page={dialog.page}
                    destinations={destinations}
                    onOpenChange={(open) => !open && setDialog(null)}
                />
            )}
            {dialog?.type === 'delete-page' && (
                <ConfirmDialog
                    key={`page-${dialog.page.id}`}
                    open
                    onOpenChange={(open) => !open && setDialog(null)}
                    title="Excluir página?"
                    description={`“${dialog.page.title}” será excluída com as imagens e PDFs dela. Não dá para desfazer.`}
                    confirmLabel="Excluir"
                    url={study.pages.destroy.url(dialog.page.id)}
                />
            )}
            {dialog?.type === 'delete-chapter' && (
                <ConfirmDialog
                    key={`chapter-${dialog.chapter.id}`}
                    open
                    onOpenChange={(open) => !open && setDialog(null)}
                    title="Excluir capítulo?"
                    description={`O capítulo “${dialog.chapter.title}” será excluído. As páginas dele não são apagadas: elas vão para “Sem capítulo”.`}
                    confirmLabel="Excluir capítulo"
                    url={study.chapters.destroy.url(dialog.chapter.id)}
                />
            )}
            {dialog?.type === 'edit-subject' && (
                <SubjectFormDialog
                    open
                    onOpenChange={(open) => !open && setDialog(null)}
                    subject={subject}
                />
            )}
        </>
    );
}

StudySubjectPage.layout = (props: { subject: StudySubject }) => ({
    breadcrumbs: [
        { title: 'Biblioteca', href: study.index() },
        { title: props.subject.title, href: study.subjects.show(props.subject.id) },
    ],
});
