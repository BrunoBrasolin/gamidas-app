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
    FolderTree,
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
import { BookCover } from '@/components/knowledge/book-cover';
import { MoveCategoryDialog } from '@/components/knowledge/move-category-dialog';
import { MovePageDialog } from '@/components/knowledge/move-page-dialog';
import { ProgressBar } from '@/components/knowledge/progress-bar';
import { PromptDialog } from '@/components/knowledge/prompt-dialog';
import {
    setPageStatus,
    StatusIcon,
} from '@/components/knowledge/status-control';
import { SubjectFormDialog } from '@/components/knowledge/subject-form-dialog';
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
    flattenCategories,
    formatRelative,
    MAX_CATEGORY_DEPTH,
    normalize,
    plural,
    STATUS_LABELS,
    STATUS_ORDER,
} from '@/lib/knowledge';
import type { KnowledgeCategoryEntry } from '@/lib/knowledge';
import { cn } from '@/lib/utils';
import knowledge from '@/routes/knowledge';
import type {
    KnowledgeCategory,
    KnowledgeDestination,
    KnowledgePageLink,
    KnowledgePageStatus,
    KnowledgePageSummary,
    KnowledgeSubject,
} from '@/types';

type Dialog =
    | { type: 'new-category'; parent: KnowledgeCategory | null }
    | { type: 'rename-category'; category: KnowledgeCategory }
    | { type: 'move-category'; entry: KnowledgeCategoryEntry }
    | { type: 'rename-page'; page: KnowledgePageSummary }
    | { type: 'move-page'; page: KnowledgePageSummary }
    | { type: 'delete-page'; page: KnowledgePageSummary }
    | { type: 'delete-category'; entry: KnowledgeCategoryEntry }
    | { type: 'edit-subject' }
    | null;

type Group = {
    entry: KnowledgeCategoryEntry | null;
    pages: KnowledgePageSummary[];
};

const keep = { preserveScroll: true, preserveState: true };

function deleteCategoryText(entry: KnowledgeCategoryEntry): string {
    const { title } = entry.category;
    const parent = entry.path.at(-2);
    const children = entry.descendants.length > 0;

    if (parent !== undefined) {
        return `A categoria “${title}” será excluída. Nada dentro dela é apagado: as páginas${children ? ' e subcategorias' : ''} vão para “${parent}”.`;
    }

    return `A categoria “${title}” será excluída. As páginas dela não são apagadas: vão para “Sem categoria”${children ? ', e as subcategorias sobem para o primeiro nível' : ''}.`;
}

function createPage(subjectId: number, categoryId: number | null) {
    router.post(knowledge.pages.store.url(subjectId), {
        title: '',
        category_id: categoryId,
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
    page: KnowledgePageSummary;
    organizing: boolean;
    canMoveUp: boolean;
    canMoveDown: boolean;
    onMove: (delta: -1 | 1) => void;
    onDialog: (dialog: Dialog) => void;
}) {
    return (
        <li className="flex items-center gap-1 rounded-lg pr-1 hover:bg-background/60">
            <Link
                href={knowledge.pages.show(page.id)}
                className="flex min-h-14 min-w-0 flex-1 items-center gap-3 rounded-lg px-3 py-2 outline-none focus-visible:ring-[3px] focus-visible:ring-ring"
            >
                <StatusIcon status={page.status} />
                <span className="min-w-0 flex-1">
                    <span className="block truncate font-serif text-[1.05rem] text-ink">
                        {page.title}
                    </span>
                    <span className="flex items-center gap-2 text-xs text-ink-soft">
                        <span>Editada {formatRelative(page.updated_at)}</span>
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
                        aria-label={`Mover ${page.title} para outra categoria`}
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
                            href={knowledge.pages.edit(page.id)}
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

function CategoryHeader({
    entry,
    title,
    count,
    organizing,
    siblings,
    onMove,
    onNewPage,
    onDialog,
}: {
    entry: KnowledgeCategoryEntry | null;
    title: string;
    count: number;
    organizing: boolean;
    siblings: KnowledgeCategoryEntry[];
    onMove: (delta: -1 | 1) => void;
    onNewPage: () => void;
    onDialog: (dialog: Dialog) => void;
}) {
    const category = entry?.category;
    const index = siblings.findIndex(
        (sibling) => sibling.category.id === category?.id,
    );

    return (
        <div className="flex items-center gap-1 border-b border-paper-edge pb-1">
            <h3
                className={cn(
                    'min-w-0 flex-1 truncate px-1 font-sans font-semibold tracking-wide text-ink-soft',
                    entry && entry.depth > 0 ? 'text-sm' : 'text-sm uppercase',
                )}
            >
                {title}
                <span className="ml-2 font-normal normal-case">{count}</span>
            </h3>
            {entry &&
                category &&
                (organizing ? (
                    <>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-10"
                            disabled={index <= 0}
                            aria-label={`Subir categoria ${category.title}`}
                            onClick={() => onMove(-1)}
                        >
                            <ArrowUp />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-10"
                            disabled={index >= siblings.length - 1}
                            aria-label={`Descer categoria ${category.title}`}
                            onClick={() => onMove(1)}
                        >
                            <ArrowDown />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-10"
                            aria-label={`Mover categoria ${category.title} para dentro de outra`}
                            onClick={() =>
                                onDialog({ type: 'move-category', entry })
                            }
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
                            aria-label={`Nova página em ${category.title}`}
                            onClick={onNewPage}
                        >
                            <Plus />
                        </Button>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="size-10"
                                    aria-label={`Ações da categoria ${category.title}`}
                                >
                                    <MoreHorizontal />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-56">
                                {entry.depth + 1 < MAX_CATEGORY_DEPTH && (
                                    <DropdownMenuItem
                                        className="min-h-10"
                                        onSelect={() =>
                                            onDialog({
                                                type: 'new-category',
                                                parent: category,
                                            })
                                        }
                                    >
                                        <FolderPlus />
                                        Nova subcategoria
                                    </DropdownMenuItem>
                                )}
                                <DropdownMenuItem
                                    className="min-h-10"
                                    onSelect={() =>
                                        onDialog({
                                            type: 'rename-category',
                                            category,
                                        })
                                    }
                                >
                                    <TextCursorInput />
                                    Renomear
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    className="min-h-10"
                                    onSelect={() =>
                                        onDialog({
                                            type: 'move-category',
                                            entry,
                                        })
                                    }
                                >
                                    <FolderTree />
                                    Mover…
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    className="min-h-10"
                                    variant="destructive"
                                    onSelect={() =>
                                        onDialog({
                                            type: 'delete-category',
                                            entry,
                                        })
                                    }
                                >
                                    <Trash2 />
                                    Excluir categoria
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </>
                ))}
        </div>
    );
}

function PageMenu({
    page,
    onDialog,
}: {
    page: KnowledgePageSummary;
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
                    <Link href={knowledge.pages.show(page.id)}>
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
                        router.post(knowledge.pages.duplicate.url(page.id))
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
                                  knowledge.pages.unbookmark.url(page.id),
                                  keep,
                              )
                            : router.post(
                                  knowledge.pages.bookmark.url(page.id),
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
                        setPageStatus(page.id, value as KnowledgePageStatus)
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
                        router.post(
                            knowledge.pages.archive.url(page.id),
                            {},
                            keep,
                        )
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

export default function KnowledgeSubjectPage({
    subject,
    categories,
    pages,
    continue_page: continuePage,
    destinations,
}: {
    subject: KnowledgeSubject;
    categories: KnowledgeCategory[];
    pages: KnowledgePageSummary[];
    continue_page: KnowledgePageLink | null;
    destinations: KnowledgeDestination[];
}) {
    const [query, setQuery] = useState('');
    const [organizing, setOrganizing] = useState(false);
    const [dialog, setDialog] = useState<Dialog>(null);

    const live = pages.filter((page) => !page.archived);
    const archived = pages.filter((page) => page.archived);

    // Categories in reading order (each one followed by its subcategories),
    // then the pages without category.
    const entries = useMemo(() => flattenCategories(categories), [categories]);
    const groups: Group[] = useMemo(() => {
        const byCategory = (id: number | null) =>
            pages
                .filter((page) => !page.archived && page.category_id === id)
                .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);

        return [
            ...entries.map((entry) => ({
                entry,
                pages: byCategory(entry.category.id),
            })),
            { entry: null, pages: byCategory(null) },
        ];
    }, [entries, pages]);

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

    function save(
        next: Group[],
        categoryIds = entries.map((entry) => entry.category.id),
    ) {
        router.put(
            knowledge.subjects.arrange.url(subject.id),
            {
                categories: categoryIds,
                pages: next.flatMap((group) =>
                    group.pages.map((page) => ({
                        id: page.id,
                        category_id: group.entry?.category.id ?? null,
                    })),
                ),
            },
            keep,
        );
    }

    const siblingsOf = (category: KnowledgeCategory) =>
        entries.filter(
            (entry) => entry.category.parent_id === category.parent_id,
        );

    // Only siblings are compared on the server, so swapping two of them in
    // the reading order is enough.
    function moveCategory(category: KnowledgeCategory, delta: -1 | 1) {
        const siblings = siblingsOf(category);
        const index = siblings.findIndex(
            (entry) => entry.category.id === category.id,
        );
        const other = siblings[index + delta]?.category;

        if (!other) {
            return;
        }

        save(
            groups,
            entries.map(({ category: { id } }) =>
                id === category.id
                    ? other.id
                    : id === other.id
                      ? category.id
                      : id,
            ),
        );
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

    const hasCategories = categories.length > 0;

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
                                    <p className="max-w-prose whitespace-pre-line text-ink-soft">
                                        {subject.description}
                                    </p>
                                )}
                            </div>
                            <div className="max-w-sm">
                                <ProgressBar
                                    counts={subject.counts}
                                    showLabel
                                />
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {(continuePage ?? firstPage) && (
                                    <Button className="h-11 px-5" asChild>
                                        <Link
                                            href={knowledge.pages.show(
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
                                        setDialog({
                                            type: 'new-category',
                                            parent: null,
                                        })
                                    }
                                >
                                    <FolderPlus />
                                    Nova categoria
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
                                                          knowledge.subjects.restore.url(
                                                              subject.id,
                                                          ),
                                                          keep,
                                                      )
                                                    : router.post(
                                                          knowledge.subjects.archive.url(
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

                    {live.length === 0 && !hasCategories ? (
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
                                    Escreva a primeira página. Categorias (e
                                    subcategorias) são opcionais — crie quando a
                                    matéria crescer.
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
                                        href={knowledge.search({
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
                                    const { entry } = group;
                                    const showHeader =
                                        entry !== null || hasCategories;

                                    if (
                                        entry === null &&
                                        group.pages.length === 0
                                    ) {
                                        return null;
                                    }

                                    return (
                                        <section
                                            key={entry?.category.id ?? 'loose'}
                                            aria-label={
                                                entry?.path.join(' › ') ??
                                                'Sem categoria'
                                            }
                                            className={cn(
                                                'space-y-1',
                                                entry !== null &&
                                                    entry.depth > 0 &&
                                                    'border-l border-paper-edge pl-3 sm:pl-4',
                                            )}
                                            style={
                                                entry
                                                    ? {
                                                          marginInlineStart: `${Math.max(0, entry.depth - 1)}rem`,
                                                      }
                                                    : undefined
                                            }
                                        >
                                            {showHeader && (
                                                <CategoryHeader
                                                    entry={entry}
                                                    title={
                                                        entry === null
                                                            ? 'Sem categoria'
                                                            : needle !== ''
                                                              ? entry.path.join(
                                                                    ' › ',
                                                                )
                                                              : entry.category
                                                                    .title
                                                    }
                                                    count={group.pages.length}
                                                    organizing={organizing}
                                                    siblings={
                                                        entry
                                                            ? siblingsOf(
                                                                  entry.category,
                                                              )
                                                            : []
                                                    }
                                                    onMove={(delta) =>
                                                        entry &&
                                                        moveCategory(
                                                            entry.category,
                                                            delta,
                                                        )
                                                    }
                                                    onNewPage={() =>
                                                        entry &&
                                                        createPage(
                                                            subject.id,
                                                            entry.category.id,
                                                        )
                                                    }
                                                    onDialog={setDialog}
                                                />
                                            )}
                                            {group.pages.length === 0 ? (
                                                entry?.descendants.length ===
                                                    0 && (
                                                    <p className="px-3 py-3 text-sm text-ink-soft">
                                                        Nenhuma página nesta
                                                        categoria.
                                                    </p>
                                                )
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
                                                href={knowledge.pages.show(
                                                    page.id,
                                                )}
                                                className="min-w-0 flex-1 truncate font-serif hover:underline"
                                            >
                                                {page.title}
                                            </Link>
                                            <Button
                                                variant="outline"
                                                className="h-10"
                                                onClick={() =>
                                                    router.delete(
                                                        knowledge.pages.restore.url(
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
                            url={knowledge.subjects.destroy.url(subject.id)}
                        />
                    </div>
                </div>
            </div>

            <PromptDialog
                open={dialog?.type === 'new-category'}
                onOpenChange={(open) => !open && setDialog(null)}
                title={
                    dialog?.type === 'new-category' && dialog.parent
                        ? `Nova subcategoria em “${dialog.parent.title}”`
                        : 'Nova categoria'
                }
                description="Categorias agrupam páginas e podem ter subcategorias. Páginas sem categoria continuam no fim do sumário."
                label="Título da categoria"
                submitLabel="Criar"
                method="post"
                url={knowledge.categories.store.url(subject.id)}
                extra={{
                    parent_id:
                        dialog?.type === 'new-category'
                            ? (dialog.parent?.id ?? null)
                            : null,
                }}
            />
            {dialog?.type === 'rename-category' && (
                <PromptDialog
                    open
                    onOpenChange={(open) => !open && setDialog(null)}
                    title="Renomear categoria"
                    label="Título da categoria"
                    initialValue={dialog.category.title}
                    method="put"
                    url={knowledge.categories.update.url(dialog.category.id)}
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
                    url={knowledge.pages.update.url(dialog.page.id)}
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
                    url={knowledge.pages.destroy.url(dialog.page.id)}
                />
            )}
            {dialog?.type === 'move-category' && (
                <MoveCategoryDialog
                    entry={dialog.entry}
                    entries={entries}
                    onOpenChange={(open) => !open && setDialog(null)}
                />
            )}
            {dialog?.type === 'delete-category' && (
                <ConfirmDialog
                    key={`category-${dialog.entry.category.id}`}
                    open
                    onOpenChange={(open) => !open && setDialog(null)}
                    title="Excluir categoria?"
                    description={deleteCategoryText(dialog.entry)}
                    confirmLabel="Excluir categoria"
                    url={knowledge.categories.destroy.url(
                        dialog.entry.category.id,
                    )}
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

KnowledgeSubjectPage.layout = (props: { subject: KnowledgeSubject }) => ({
    breadcrumbs: [
        { title: 'Base de Conhecimento', href: knowledge.index() },
        {
            title: props.subject.title,
            href: knowledge.subjects.show(props.subject.id),
        },
    ],
});
