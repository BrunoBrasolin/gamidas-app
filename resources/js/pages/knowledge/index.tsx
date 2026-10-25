import { Head, Link, router } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    ArrowLeft,
    ArrowRight,
    Bookmark,
    BookOpen,
    LibraryBig,
    MoreHorizontal,
    Pencil,
    Pin,
    PinOff,
    Plus,
    Search,
    Trash2,
} from 'lucide-react';
import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import { ConfirmDialog } from '@/components/finance/confirm-dialog';
import { BookCover } from '@/components/knowledge/book-cover';
import { ProgressBar } from '@/components/knowledge/progress-bar';
import { StatusIcon } from '@/components/knowledge/status-control';
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
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import {
    formatRelative,
    normalize,
    plural,
    preferences,
    KNOWLEDGE_COLORS,
} from '@/lib/knowledge';
import knowledge from '@/routes/knowledge';
import type {
    KnowledgeLibrarySubject,
    KnowledgePageWithSubject,
    KnowledgeSubject,
} from '@/types';

type SortMode = 'recent' | 'title' | 'manual';

const SORT_KEY = 'knowledge.library.sort';

function sortSubjects(
    subjects: KnowledgeLibrarySubject[],
    mode: SortMode,
): KnowledgeLibrarySubject[] {
    const sorted = [...subjects];

    if (mode === 'title') {
        sorted.sort((a, b) =>
            a.title.localeCompare(b.title, 'pt-BR', { sensitivity: 'base' }),
        );
    } else if (mode === 'recent') {
        const time = (subject: KnowledgeSubject) =>
            Math.max(
                Date.parse(subject.updated_at ?? '') || 0,
                Date.parse(subject.last_opened_at ?? '') || 0,
            );
        sorted.sort((a, b) => time(b) - time(a));
    } else {
        sorted.sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);
    }

    // Pinned subjects always come first, keeping the chosen order.
    return [
        ...sorted.filter((subject) => subject.is_pinned),
        ...sorted.filter((subject) => !subject.is_pinned),
    ];
}

function SubjectActions({
    subject,
    onEdit,
}: {
    subject: KnowledgeSubject;
    onEdit: () => void;
}) {
    const options = { preserveScroll: true };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-10 shrink-0"
                    aria-label={`Ações de ${subject.title}`}
                >
                    <MoreHorizontal />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-52">
                <DropdownMenuItem className="min-h-10" onSelect={onEdit}>
                    <Pencil />
                    Editar
                </DropdownMenuItem>
                <DropdownMenuItem
                    className="min-h-10"
                    onSelect={() =>
                        subject.is_pinned
                            ? router.delete(
                                  knowledge.subjects.unpin.url(subject.id),
                                  options,
                              )
                            : router.post(
                                  knowledge.subjects.pin.url(subject.id),
                                  {},
                                  options,
                              )
                    }
                >
                    {subject.is_pinned ? <PinOff /> : <Pin />}
                    {subject.is_pinned ? 'Desafixar' : 'Fixar no topo'}
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    className="min-h-10"
                    onSelect={() =>
                        router.post(
                            knowledge.subjects.archive.url(subject.id),
                            {},
                            options,
                        )
                    }
                >
                    <Archive />
                    Arquivar
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function SubjectCard({
    subject,
    organizing,
    canMoveBack,
    canMoveForward,
    onMove,
    onEdit,
}: {
    subject: KnowledgeLibrarySubject;
    organizing: boolean;
    canMoveBack: boolean;
    canMoveForward: boolean;
    onMove: (delta: -1 | 1) => void;
    onEdit: () => void;
}) {
    const { counts } = subject;

    return (
        <li className="flex flex-col gap-3">
            <Link
                href={knowledge.subjects.show(subject.id)}
                className="group rounded-md outline-none focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:ring-offset-4 focus-visible:ring-offset-paper"
                prefetch
            >
                <BookCover
                    title={subject.title}
                    color={subject.accent_color}
                    coverUrl={subject.cover_url}
                    pinned={subject.is_pinned}
                    className="transition-transform duration-200 group-hover:-translate-y-1 group-active:scale-[0.98]"
                />
            </Link>
            <div className="flex items-start gap-1">
                <div className="min-w-0 flex-1 space-y-1">
                    <Link
                        href={knowledge.subjects.show(subject.id)}
                        className="line-clamp-2 font-serif text-base leading-snug font-semibold text-ink hover:underline"
                    >
                        {subject.title}
                    </Link>
                    <p className="text-xs text-ink-soft">
                        {plural(counts.pages, 'página', 'páginas')}
                        {counts.attachments > 0 &&
                            ` · ${plural(counts.attachments, 'anexo', 'anexos')}`}
                    </p>
                </div>
                {!organizing && (
                    <SubjectActions subject={subject} onEdit={onEdit} />
                )}
            </div>
            {counts.pages > 0 && <ProgressBar counts={counts} />}
            {organizing ? (
                <div className="flex gap-2">
                    <Button
                        variant="outline"
                        className="h-10 flex-1"
                        disabled={!canMoveBack}
                        onClick={() => onMove(-1)}
                        aria-label={`Mover ${subject.title} para antes`}
                    >
                        <ArrowLeft />
                    </Button>
                    <Button
                        variant="outline"
                        className="h-10 flex-1"
                        disabled={!canMoveForward}
                        onClick={() => onMove(1)}
                        aria-label={`Mover ${subject.title} para depois`}
                    >
                        <ArrowRight />
                    </Button>
                </div>
            ) : (
                subject.continue_page && (
                    <Button
                        variant="secondary"
                        className="h-10 justify-start overflow-hidden"
                        asChild
                    >
                        <Link
                            href={knowledge.pages.show(
                                subject.continue_page.id,
                            )}
                        >
                            <BookOpen />
                            <span className="truncate">
                                Continuar: {subject.continue_page.title}
                            </span>
                        </Link>
                    </Button>
                )
            )}
        </li>
    );
}

function PageLinkCard({ page }: { page: KnowledgePageWithSubject }) {
    const palette = KNOWLEDGE_COLORS[page.subject.accent_color];

    return (
        <Link
            href={knowledge.pages.show(page.id)}
            className="flex min-h-16 w-64 shrink-0 items-center gap-3 rounded-xl border border-paper-edge bg-background/70 p-3 shadow-xs outline-none hover:bg-background focus-visible:ring-[3px] focus-visible:ring-ring sm:w-72"
        >
            <span
                className="h-12 w-2 shrink-0 rounded-full"
                style={{ background: palette.light }}
                aria-hidden
            />
            <span className="min-w-0 flex-1">
                <span className="block truncate font-serif font-semibold text-ink">
                    {page.title}
                </span>
                <span className="block truncate text-xs text-ink-soft">
                    {page.subject.title}
                    {page.last_read_at &&
                        ` · ${formatRelative(page.last_read_at)}`}
                </span>
            </span>
            <StatusIcon status={page.status} />
        </Link>
    );
}

export default function KnowledgeLibrary({
    subjects,
    recent_pages: recentPages,
    bookmarks,
}: {
    subjects: KnowledgeLibrarySubject[];
    recent_pages: KnowledgePageWithSubject[];
    bookmarks: KnowledgePageWithSubject[];
}) {
    const [query, setQuery] = useState('');
    const [sort, setSort] = useState<SortMode>(() =>
        preferences.get<SortMode>(SORT_KEY, 'recent'),
    );
    const [organizing, setOrganizing] = useState(false);
    const [creating, setCreating] = useState(false);
    const [editing, setEditing] = useState<KnowledgeSubject | null>(null);

    const active = subjects.filter((subject) => !subject.archived);
    const archived = subjects.filter((subject) => subject.archived);

    const shelf = useMemo(() => {
        const needle = normalize(query.trim());
        const sorted = sortSubjects(
            subjects.filter((subject) => !subject.archived),
            organizing ? 'manual' : sort,
        );

        return needle === ''
            ? sorted
            : sorted.filter((subject) =>
                  normalize(subject.title).includes(needle),
              );
    }, [subjects, query, sort, organizing]);

    function changeSort(mode: SortMode) {
        setSort(mode);
        preferences.set(SORT_KEY, mode);
    }

    function move(index: number, delta: -1 | 1) {
        const order = sortSubjects(active, 'manual').map((s) => s.id);
        const id = shelf[index].id;
        const target = shelf[index + delta]?.id;
        const from = order.indexOf(id);
        const to = order.indexOf(target);

        if (target === undefined || from < 0 || to < 0) {
            return;
        }

        order.splice(from, 1);
        order.splice(to, 0, id);

        router.put(
            knowledge.subjects.order.url(),
            { ids: order },
            { preserveScroll: true, preserveState: true },
        );
    }

    function searchEverywhere(event: FormEvent) {
        event.preventDefault();

        if (query.trim().length >= 2) {
            router.get(knowledge.search.url({ query: { q: query.trim() } }));
        }
    }

    return (
        <>
            <Head title="Base de Conhecimento" />
            <div className="flex flex-1 flex-col bg-paper text-ink md:rounded-xl">
                <div className="mx-auto w-full max-w-6xl space-y-10 px-4 py-6 sm:px-6 sm:py-8">
                    <header className="flex flex-col gap-5 md:flex-row md:items-end md:justify-between">
                        <div className="space-y-1">
                            <p className="text-sm font-medium tracking-wide text-ink-soft uppercase">
                                Base de Conhecimento
                            </p>
                            <h1 className="font-serif text-3xl font-semibold tracking-tight sm:text-4xl">
                                Suas matérias
                            </h1>
                        </div>
                        <Button
                            className="h-11 self-start px-5 md:self-auto"
                            onClick={() => setCreating(true)}
                        >
                            <Plus />
                            Nova matéria
                        </Button>
                    </header>

                    {recentPages.length > 0 && (
                        <section
                            aria-labelledby="recent-title"
                            className="space-y-3"
                        >
                            <h2
                                id="recent-title"
                                className="font-serif text-xl font-semibold"
                            >
                                Continuar lendo
                            </h2>
                            <div className="-mx-4 flex [scrollbar-width:thin] gap-3 overflow-x-auto px-4 pb-2 sm:-mx-6 sm:px-6">
                                {recentPages.map((page) => (
                                    <PageLinkCard key={page.id} page={page} />
                                ))}
                            </div>
                        </section>
                    )}

                    <section
                        aria-labelledby="shelf-title"
                        className="@container space-y-5"
                    >
                        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <h2
                                id="shelf-title"
                                className="font-serif text-xl font-semibold"
                            >
                                Matérias
                                <span className="ml-2 font-sans text-sm font-normal text-ink-soft">
                                    {active.length}
                                </span>
                            </h2>
                            {active.length > 0 && (
                                <div className="flex flex-wrap items-center gap-2">
                                    <form
                                        onSubmit={searchEverywhere}
                                        className="relative min-w-0 flex-1 sm:w-72 sm:flex-none"
                                        role="search"
                                    >
                                        <Search
                                            className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-ink-soft"
                                            aria-hidden
                                        />
                                        <Input
                                            type="search"
                                            enterKeyHint="search"
                                            placeholder="Filtrar matérias"
                                            aria-label="Filtrar matérias pelo título"
                                            className="h-11 bg-background/70 pl-9 text-base"
                                            value={query}
                                            onChange={(event) =>
                                                setQuery(event.target.value)
                                            }
                                        />
                                    </form>
                                    <NativeSelect
                                        aria-label="Ordenar matérias"
                                        className="h-11 w-auto bg-background/70"
                                        value={organizing ? 'manual' : sort}
                                        disabled={organizing}
                                        onChange={(event) =>
                                            changeSort(
                                                event.target.value as SortMode,
                                            )
                                        }
                                    >
                                        <option value="recent">
                                            Mais recentes
                                        </option>
                                        <option value="title">A–Z</option>
                                        <option value="manual">
                                            Minha ordem
                                        </option>
                                    </NativeSelect>
                                    <Button
                                        variant={
                                            organizing ? 'default' : 'outline'
                                        }
                                        className="h-11"
                                        aria-pressed={organizing}
                                        onClick={() => {
                                            setOrganizing(!organizing);
                                            setQuery('');

                                            if (!organizing) {
                                                changeSort('manual');
                                            }
                                        }}
                                    >
                                        {organizing ? 'Concluir' : 'Organizar'}
                                    </Button>
                                </div>
                            )}
                        </div>

                        {active.length === 0 ? (
                            <div className="flex flex-col items-center gap-4 rounded-2xl border border-dashed border-paper-edge px-6 py-16 text-center">
                                <LibraryBig
                                    className="size-10 text-ink-soft"
                                    aria-hidden
                                />
                                <div className="space-y-1">
                                    <p className="font-serif text-lg font-semibold">
                                        Sua estante está vazia
                                    </p>
                                    <p className="max-w-md text-sm text-ink-soft">
                                        Crie uma matéria para cada assunto.
                                        Dentro dela, organize categorias e
                                        escreva páginas com texto, imagens e
                                        PDFs.
                                    </p>
                                </div>
                                <Button
                                    className="h-11 px-5"
                                    onClick={() => setCreating(true)}
                                >
                                    <Plus />
                                    Criar a primeira matéria
                                </Button>
                            </div>
                        ) : shelf.length === 0 ? (
                            <div className="space-y-3 text-sm text-ink-soft">
                                <p>Nenhuma matéria com esse título.</p>
                                <Button
                                    variant="outline"
                                    className="h-10"
                                    asChild
                                >
                                    <Link
                                        href={knowledge.search({
                                            query: { q: query.trim() },
                                        })}
                                    >
                                        <Search />
                                        Buscar “{query.trim()}” em todas as
                                        páginas
                                    </Link>
                                </Button>
                            </div>
                        ) : (
                            <>
                                {organizing && (
                                    <p className="text-sm text-ink-soft">
                                        Use as setas para mudar a ordem dos
                                        livros. As fixadas ficam sempre na
                                        frente.
                                    </p>
                                )}
                                <ul className="grid grid-cols-2 gap-x-5 gap-y-8 @md:grid-cols-3 @2xl:grid-cols-4 @4xl:grid-cols-5">
                                    {shelf.map((subject, index) => (
                                        <SubjectCard
                                            key={subject.id}
                                            subject={subject}
                                            organizing={organizing}
                                            canMoveBack={
                                                index > 0 &&
                                                shelf[index - 1].is_pinned ===
                                                    subject.is_pinned
                                            }
                                            canMoveForward={
                                                index < shelf.length - 1 &&
                                                shelf[index + 1].is_pinned ===
                                                    subject.is_pinned
                                            }
                                            onMove={(delta) =>
                                                move(index, delta)
                                            }
                                            onEdit={() => setEditing(subject)}
                                        />
                                    ))}
                                </ul>
                                {query.trim().length >= 2 && (
                                    <Button
                                        variant="link"
                                        className="h-10 px-0 text-ink-soft"
                                        asChild
                                    >
                                        <Link
                                            href={knowledge.search({
                                                query: { q: query.trim() },
                                            })}
                                        >
                                            Buscar “{query.trim()}” também
                                            dentro das páginas
                                        </Link>
                                    </Button>
                                )}
                            </>
                        )}
                    </section>

                    {bookmarks.length > 0 && (
                        <section
                            aria-labelledby="bookmarks-title"
                            className="space-y-3"
                        >
                            <h2
                                id="bookmarks-title"
                                className="flex items-center gap-2 font-serif text-xl font-semibold"
                            >
                                <Bookmark className="size-5" aria-hidden />
                                Marcadas
                            </h2>
                            <div className="-mx-4 flex gap-3 overflow-x-auto px-4 pb-2 sm:-mx-6 sm:px-6">
                                {bookmarks.map((page) => (
                                    <PageLinkCard key={page.id} page={page} />
                                ))}
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
                                    Arquivadas ({archived.length})
                                </Button>
                            </CollapsibleTrigger>
                            <CollapsibleContent>
                                <ul className="mt-3 divide-y divide-paper-edge rounded-xl border border-paper-edge bg-background/60">
                                    {archived.map((subject) => (
                                        <li
                                            key={subject.id}
                                            className="flex items-center gap-3 p-3"
                                        >
                                            <div className="w-10 shrink-0">
                                                <BookCover
                                                    title={subject.title}
                                                    color={subject.accent_color}
                                                    coverUrl={subject.cover_url}
                                                    size="sm"
                                                />
                                            </div>
                                            <Link
                                                href={knowledge.subjects.show(
                                                    subject.id,
                                                )}
                                                className="min-w-0 flex-1 truncate font-medium hover:underline"
                                            >
                                                {subject.title}
                                            </Link>
                                            <Button
                                                variant="outline"
                                                className="h-10"
                                                onClick={() =>
                                                    router.delete(
                                                        knowledge.subjects.restore.url(
                                                            subject.id,
                                                        ),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                <ArchiveRestore />
                                                Restaurar
                                            </Button>
                                            <ConfirmDialog
                                                trigger={
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-10 text-destructive"
                                                        aria-label={`Excluir ${subject.title}`}
                                                    >
                                                        <Trash2 />
                                                    </Button>
                                                }
                                                title="Excluir matéria?"
                                                description={`“${subject.title}” será excluída com todas as páginas, imagens e PDFs. Não dá para desfazer.`}
                                                confirmLabel="Excluir"
                                                url={knowledge.subjects.destroy.url(
                                                    subject.id,
                                                )}
                                            />
                                        </li>
                                    ))}
                                </ul>
                            </CollapsibleContent>
                        </Collapsible>
                    )}
                </div>
            </div>

            <SubjectFormDialog open={creating} onOpenChange={setCreating} />
            {editing && (
                <SubjectFormDialog
                    key={editing.id}
                    open
                    onOpenChange={(open) => !open && setEditing(null)}
                    subject={editing}
                />
            )}
        </>
    );
}

KnowledgeLibrary.layout = {
    breadcrumbs: [{ title: 'Base de Conhecimento', href: knowledge.index() }],
};
