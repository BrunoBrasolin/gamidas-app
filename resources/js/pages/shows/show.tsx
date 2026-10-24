import { Head, Link } from '@inertiajs/react';
import { Calendar, Images, MapPin, Pencil, Trash2 } from 'lucide-react';
import { ConfirmDialog } from '@/components/finance/confirm-dialog';
import { PageHeader } from '@/components/finance/page-header';
import { Setlist, showTitle } from '@/components/shows/setlist';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatDate } from '@/lib/format';
import shows from '@/routes/shows';
import type { Show } from '@/types';

export default function ShowDetails({ show }: { show: Show }) {
    return (
        <>
            <Head title={showTitle(show)} />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title={showTitle(show)}
                    description={
                        <span className="flex flex-wrap items-center gap-x-4 gap-y-1">
                            <span className="flex items-center gap-1.5">
                                <Calendar className="size-3.5" aria-hidden />
                                {formatDate(show.date)}
                            </span>
                            <span className="flex items-center gap-1.5">
                                <MapPin className="size-3.5" aria-hidden />
                                {show.venue}
                            </span>
                            {show.created_by && (
                                <span>Cadastrado por {show.created_by}</span>
                            )}
                        </span>
                    }
                >
                    {show.photos_url && (
                        <Button variant="outline" asChild>
                            <a
                                href={show.photos_url}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <Images />
                                Álbum de fotos
                            </a>
                        </Button>
                    )}
                    <Button variant="outline" asChild>
                        <Link href={shows.edit(show.id)}>
                            <Pencil />
                            Editar
                        </Link>
                    </Button>
                    <ConfirmDialog
                        trigger={
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Excluir show"
                            >
                                <Trash2 />
                            </Button>
                        }
                        title="Excluir show?"
                        description={`“${showTitle(show)}” em ${formatDate(show.date)} será removido, com setlist e aberturas.`}
                        confirmLabel="Excluir"
                        url={shows.destroy.url(show.id)}
                    />
                </PageHeader>

                <Card>
                    <CardHeader>
                        <CardTitle>Setlist</CardTitle>
                        <CardDescription>
                            {show.band} · {show.setlist.length}{' '}
                            {show.setlist.length === 1 ? 'música' : 'músicas'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Setlist songs={show.setlist} />
                    </CardContent>
                </Card>

                {show.opening_acts.map((act, index) => (
                    <Card key={index}>
                        <CardHeader>
                            <CardTitle>{act.band}</CardTitle>
                            <CardDescription>
                                Show de abertura · {act.setlist.length}{' '}
                                {act.setlist.length === 1
                                    ? 'música'
                                    : 'músicas'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Setlist songs={act.setlist} />
                        </CardContent>
                    </Card>
                ))}
            </div>
        </>
    );
}

ShowDetails.layout = {
    breadcrumbs: [
        { title: 'Shows', href: shows.index() },
        { title: 'Detalhes', href: shows.index() },
    ],
};
