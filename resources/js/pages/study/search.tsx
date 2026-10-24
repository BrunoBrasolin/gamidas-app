import { Head, Link, router } from '@inertiajs/react';
import { FolderOpen, Search, X } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { BookCover } from '@/components/study/book-cover';
import { StatusIcon } from '@/components/study/status-control';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatRelative, plural, STUDY_COLORS } from '@/lib/study';
import study from '@/routes/study';
import type { StudySearchResults } from '@/types';

export default function StudySearch({
    query,
    subject,
    results,
}: {
    query: string;
    subject: { id: number; title: string } | null;
    results: StudySearchResults;
}) {
    const [value, setValue] = useState(query);
    const total =
        results.subjects.length +
        results.chapters.length +
        results.pages.length;

    function submit(event: FormEvent) {
        event.preventDefault();
        router.get(
            study.search.url({
                query: {
                    q: value.trim(),
                    ...(subject ? { subject: subject.id } : {}),
                },
            }),
            {},
            { preserveState: true },
        );
    }

    return (
        <>
            <Head title={query ? `Busca: ${query}` : 'Buscar nos estudos'} />
            <div className="flex flex-1 flex-col bg-paper text-ink md:rounded-xl">
                <div className="mx-auto w-full max-w-3xl space-y-8 px-4 py-6 sm:px-6 sm:py-8">
                    <header className="space-y-4">
                        <h1 className="font-serif text-3xl font-semibold tracking-tight">
                            Buscar
                        </h1>
                        <form onSubmit={submit} role="search" className="flex gap-2">
                            <div className="relative flex-1">
                                <Search
                                    className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-ink-soft"
                                    aria-hidden
                                />
                                <Input
                                    type="search"
                                    enterKeyHint="search"
                                    autoFocus={query === ''}
                                    value={value}
                                    placeholder="Matérias, capítulos, páginas e texto"
                                    aria-label="Buscar nos estudos"
                                    className="h-12 bg-background/70 pl-9 text-base"
                                    onChange={(event) =>
                                        setValue(event.target.value)
                                    }
                                />
                            </div>
                            <Button type="submit" className="h-12 px-5">
                                Buscar
                            </Button>
                        </form>
                        {subject && (
                            <p className="flex flex-wrap items-center gap-2 text-sm text-ink-soft">
                                Buscando só em
                                <span className="font-medium text-ink">
                                    {subject.title}
                                </span>
                                <Button
                                    variant="ghost"
                                    className="h-9 px-2"
                                    asChild
                                >
                                    <Link
                                        href={study.search({
                                            query: { q: query },
                                        })}
                                    >
                                        <X />
                                        Buscar em tudo
                                    </Link>
                                </Button>
                            </p>
                        )}
                    </header>

                    {query.length >= 2 && total === 0 && (
                        <p className="text-ink-soft">
                            Nada encontrado para “{query}”.
                        </p>
                    )}
                    {query.length > 0 && query.length < 2 && (
                        <p className="text-ink-soft">
                            Digite pelo menos 2 letras.
                        </p>
                    )}

                    {results.subjects.length > 0 && (
                        <section className="space-y-3" aria-labelledby="r-subjects">
                            <h2 id="r-subjects" className="font-serif text-lg font-semibold">
                                Matérias
                            </h2>
                            <ul className="grid grid-cols-3 gap-4 sm:grid-cols-5">
                                {results.subjects.map((item) => (
                                    <li key={item.id}>
                                        <Link
                                            href={study.subjects.show(item.id)}
                                            className="block space-y-2"
                                        >
                                            <BookCover
                                                title={item.title}
                                                color={item.accent_color}
                                                coverUrl={item.cover_url}
                                                size="sm"
                                            />
                                            <span className="line-clamp-2 font-serif text-sm font-semibold">
                                                {item.title}
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    {results.chapters.length > 0 && (
                        <section className="space-y-3" aria-labelledby="r-chapters">
                            <h2 id="r-chapters" className="font-serif text-lg font-semibold">
                                Capítulos
                            </h2>
                            <ul className="divide-y divide-paper-edge rounded-xl border border-paper-edge bg-background/60">
                                {results.chapters.map((chapter) => (
                                    <li key={chapter.id}>
                                        <Link
                                            href={study.subjects.show(
                                                chapter.subject.id,
                                            )}
                                            className="flex min-h-14 items-center gap-3 px-4 py-2 hover:bg-background"
                                        >
                                            <FolderOpen
                                                className="size-4 shrink-0 text-ink-soft"
                                                aria-hidden
                                            />
                                            <span className="min-w-0">
                                                <span className="block truncate font-medium">
                                                    {chapter.title}
                                                </span>
                                                <span className="block truncate text-xs text-ink-soft">
                                                    {chapter.subject.title}
                                                </span>
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    {results.pages.length > 0 && (
                        <section className="space-y-3" aria-labelledby="r-pages">
                            <h2 id="r-pages" className="font-serif text-lg font-semibold">
                                Páginas
                                <span className="ml-2 font-sans text-sm font-normal text-ink-soft">
                                    {plural(results.pages.length, 'resultado', 'resultados')}
                                </span>
                            </h2>
                            <ul className="space-y-2">
                                {results.pages.map((page) => (
                                    <li key={page.id}>
                                        <Link
                                            href={study.pages.show(page.id)}
                                            className="block space-y-1.5 rounded-xl border border-paper-edge bg-background/60 p-4 hover:bg-background"
                                        >
                                            <span className="flex items-center gap-2">
                                                <StatusIcon status={page.status} />
                                                <span className="truncate font-serif text-lg font-semibold">
                                                    {page.title}
                                                </span>
                                            </span>
                                            <span className="flex items-center gap-2 text-xs text-ink-soft">
                                                <span
                                                    className="size-2 rounded-full"
                                                    style={{
                                                        background:
                                                            STUDY_COLORS[
                                                                page.subject
                                                                    .accent_color
                                                            ].light,
                                                    }}
                                                    aria-hidden
                                                />
                                                {page.subject.title} · editada{' '}
                                                {formatRelative(page.updated_at)}
                                            </span>
                                            {page.excerpt && (
                                                <span className="line-clamp-3 block font-serif text-sm text-ink-soft">
                                                    {page.excerpt}
                                                </span>
                                            )}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                </div>
            </div>
        </>
    );
}

StudySearch.layout = {
    breadcrumbs: [
        { title: 'Biblioteca', href: study.index() },
        { title: 'Buscar', href: study.search() },
    ],
};
