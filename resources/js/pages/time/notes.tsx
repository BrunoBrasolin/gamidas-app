import { Head, Link } from '@inertiajs/react';
import { NotebookPen, Search } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/finance/empty-state';
import { PageHeader } from '@/components/finance/page-header';
import { Pagination } from '@/components/finance/pagination';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useQueryFilters } from '@/hooks/use-query-filters';
import { capitalize, formatDate, today } from '@/lib/format';
import { weekday } from '@/lib/time';
import time from '@/routes/time';
import type { Paginated } from '@/types';

type Props = {
    filters: { q: string };
    notes: Paginated<{
        date: string;
        preview: string;
        updated_at: string | null;
    }>;
};

export default function TimeNotes({ filters, notes }: Props) {
    const filter = useQueryFilters(time.notes.index.url(), filters);
    const [search, setSearch] = useState(filters.q);

    return (
        <>
            <Head title="Notas diárias" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Notas diárias"
                    description="Uma nota em Markdown por dia. Busque pelo texto ou abra um dia para escrever."
                >
                    <Button asChild>
                        <Link href={time.days.show(today())}>
                            <NotebookPen />
                            Nota de hoje
                        </Link>
                    </Button>
                </PageHeader>

                <form
                    role="search"
                    className="flex max-w-lg gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        filter({ q: search.trim() });
                    }}
                >
                    <label htmlFor="notes-search" className="sr-only">
                        Buscar nas notas
                    </label>
                    <Input
                        id="notes-search"
                        type="search"
                        value={search}
                        maxLength={100}
                        placeholder="Buscar nas notas…"
                        onChange={(event) => setSearch(event.target.value)}
                    />
                    <Button type="submit" variant="outline">
                        <Search />
                        Buscar
                    </Button>
                </form>

                {notes.data.length === 0 ? (
                    <EmptyState
                        icon={NotebookPen}
                        title={
                            filters.q
                                ? 'Nenhuma nota encontrada'
                                : 'Nenhuma nota ainda'
                        }
                        description={
                            filters.q
                                ? `Nenhuma nota contém “${filters.q}”.`
                                : 'As notas escritas na visão do dia aparecem aqui.'
                        }
                    />
                ) : (
                    <ul className="grid gap-3">
                        {notes.data.map((note) => (
                            <li key={note.date}>
                                <Card className="py-3">
                                    <CardContent className="px-4">
                                        <Link
                                            href={time.days.show(note.date)}
                                            className="block space-y-1 hover:underline-offset-2"
                                        >
                                            <div className="font-medium">
                                                {capitalize(weekday(note.date))}
                                                , {formatDate(note.date)}
                                            </div>
                                            <p className="text-sm text-muted-foreground">
                                                {note.preview}
                                            </p>
                                        </Link>
                                    </CardContent>
                                </Card>
                            </li>
                        ))}
                    </ul>
                )}

                <Pagination page={notes} />
            </div>
        </>
    );
}

TimeNotes.layout = {
    breadcrumbs: [
        { title: 'Tarefas e tempo', href: time.index() },
        { title: 'Notas diárias', href: time.notes.index() },
    ],
};
