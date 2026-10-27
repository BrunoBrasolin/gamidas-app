import { Head, Link } from '@inertiajs/react';
import { ArrowUpRight, FolderOpen, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { FileCard } from '@/components/knowledge/attachment-viewers';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    formatRelative,
    KNOWLEDGE_COLORS,
    normalize,
    plural,
} from '@/lib/knowledge';
import knowledge from '@/routes/knowledge';
import type { KnowledgeAttachmentKind, KnowledgeFileEntry } from '@/types';

type KindFilter = 'all' | KnowledgeAttachmentKind;

const KIND_FILTERS: { value: KindFilter; label: string }[] = [
    { value: 'all', label: 'Todos' },
    { value: 'pdf', label: 'PDF' },
    { value: 'slides', label: 'Slides' },
    { value: 'document', label: 'Word' },
    { value: 'sheet', label: 'Excel' },
    { value: 'image', label: 'Imagens' },
];

/** Where a file lives, shown under its name. */
function placeOf(file: KnowledgeFileEntry): string {
    const subject = `${file.subject.title}${file.subject.archived ? ' (arquivada)' : ''}`;
    const where = file.page
        ? `na página “${file.page.title}”`
        : 'anexo da matéria';

    return `${subject} · ${where} · ${formatRelative(file.added_at)}`;
}

/**
 * Every file of the knowledge base, newest first, with the subject (and page)
 * each one belongs to.
 */
export default function KnowledgeFiles({
    files,
}: {
    files: KnowledgeFileEntry[];
}) {
    const [query, setQuery] = useState('');
    const [subjectId, setSubjectId] = useState('all');
    const [kind, setKind] = useState<KindFilter>('all');

    const subjects = useMemo(() => {
        const byId = new Map<number, KnowledgeFileEntry['subject']>();
        files.forEach((file) => byId.set(file.subject.id, file.subject));

        return [...byId.values()].sort((a, b) =>
            a.title.localeCompare(b.title, 'pt-BR'),
        );
    }, [files]);

    const visible = files.filter(
        (file) =>
            (subjectId === 'all' || String(file.subject.id) === subjectId) &&
            (kind === 'all' || file.kind === kind) &&
            normalize(file.name).includes(normalize(query.trim())),
    );

    return (
        <>
            <Head title="Arquivos" />
            <div className="flex flex-1 flex-col bg-paper text-ink md:rounded-xl">
                <div className="mx-auto w-full max-w-3xl space-y-6 px-4 py-6 sm:px-6 sm:py-8">
                    <header className="space-y-1">
                        <h1 className="font-serif text-3xl font-semibold tracking-tight">
                            Arquivos
                        </h1>
                        <p className="text-sm text-ink-soft">
                            Anexos das matérias e arquivos das páginas, do mais
                            novo para o mais antigo.
                        </p>
                    </header>

                    {files.length === 0 ? (
                        <div className="flex flex-col items-center gap-4 rounded-2xl border border-dashed border-paper-edge px-6 py-14 text-center">
                            <FolderOpen
                                className="size-10 text-ink-soft"
                                aria-hidden
                            />
                            <div className="space-y-1">
                                <p className="font-serif text-lg font-semibold">
                                    Nenhum arquivo ainda
                                </p>
                                <p className="max-w-md text-sm text-ink-soft">
                                    Abra uma matéria e use “Adicionar anexo”
                                    para guardar slides, apostilas e documentos.
                                </p>
                            </div>
                            <Button className="h-11 px-5" asChild>
                                <Link href={knowledge.index()}>
                                    Ver matérias
                                </Link>
                            </Button>
                        </div>
                    ) : (
                        <>
                            <div className="space-y-3">
                                <div className="flex flex-col gap-2 sm:flex-row">
                                    <div className="relative flex-1">
                                        <Search
                                            className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-ink-soft"
                                            aria-hidden
                                        />
                                        <Input
                                            type="search"
                                            value={query}
                                            placeholder="Buscar pelo nome"
                                            aria-label="Buscar arquivos pelo nome"
                                            className="h-11 bg-background/70 pl-9"
                                            onChange={(event) =>
                                                setQuery(event.target.value)
                                            }
                                        />
                                    </div>
                                    <NativeSelect
                                        value={subjectId}
                                        aria-label="Matéria"
                                        className="h-11 bg-background/70 sm:w-56"
                                        onChange={(event) =>
                                            setSubjectId(event.target.value)
                                        }
                                    >
                                        <option value="all">
                                            Todas as matérias
                                        </option>
                                        {subjects.map((subject) => (
                                            <option
                                                key={subject.id}
                                                value={String(subject.id)}
                                            >
                                                {subject.title}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </div>
                                <ToggleGroup
                                    type="single"
                                    value={kind}
                                    onValueChange={(value) =>
                                        setKind((value || 'all') as KindFilter)
                                    }
                                    className="flex-wrap justify-start"
                                    aria-label="Tipo de arquivo"
                                >
                                    {KIND_FILTERS.map((filter) => (
                                        <ToggleGroupItem
                                            key={filter.value}
                                            value={filter.value}
                                            className="h-9 px-3"
                                        >
                                            {filter.label}
                                        </ToggleGroupItem>
                                    ))}
                                </ToggleGroup>
                            </div>

                            <p className="text-sm text-ink-soft">
                                {plural(visible.length, 'arquivo', 'arquivos')}
                            </p>

                            {visible.length === 0 ? (
                                <p className="rounded-xl border border-dashed border-paper-edge px-4 py-8 text-center text-sm text-ink-soft">
                                    Nenhum arquivo com esses filtros.
                                </p>
                            ) : (
                                <ul className="space-y-2">
                                    {visible.map((file) => (
                                        <li key={file.id}>
                                            <FileCard
                                                attachment={file}
                                                details={
                                                    <>
                                                        <span
                                                            className="mr-1.5 inline-block size-2 rounded-full align-middle"
                                                            style={{
                                                                background:
                                                                    KNOWLEDGE_COLORS[
                                                                        file
                                                                            .subject
                                                                            .accent_color
                                                                    ].light,
                                                            }}
                                                            aria-hidden
                                                        />
                                                        {placeOf(file)}
                                                    </>
                                                }
                                                actions={
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-10"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={
                                                                file.page
                                                                    ? knowledge.pages.show(
                                                                          file
                                                                              .page
                                                                              .id,
                                                                      )
                                                                    : knowledge.subjects.show(
                                                                          file
                                                                              .subject
                                                                              .id,
                                                                      )
                                                            }
                                                            aria-label={
                                                                file.page
                                                                    ? `Ir para a página ${file.page.title}`
                                                                    : `Ir para a matéria ${file.subject.title}`
                                                            }
                                                            title={
                                                                file.page
                                                                    ? 'Ir para a página'
                                                                    : 'Ir para a matéria'
                                                            }
                                                        >
                                                            <ArrowUpRight />
                                                        </Link>
                                                    </Button>
                                                }
                                            />
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </>
                    )}
                </div>
            </div>
        </>
    );
}

KnowledgeFiles.layout = {
    breadcrumbs: [
        { title: 'Base de Conhecimento', href: knowledge.index() },
        { title: 'Arquivos', href: knowledge.files() },
    ],
};
