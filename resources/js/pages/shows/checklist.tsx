import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormField } from '@/components/finance/form-field';
import { PageHeader } from '@/components/finance/page-header';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import shows from '@/routes/shows';

/** The checklist is edited as text, one item per line. */
export default function ShowChecklistPage({ items }: { items: string[] }) {
    const form = useForm({ items: items.join('\n') });
    const count = form.data.items
        .split('\n')
        .filter((line) => line.trim() !== '').length;

    function submit(event: FormEvent) {
        event.preventDefault();
        form.submit(shows.checklist.update(), { preserveScroll: true });
    }

    return (
        <>
            <Head title="Checklist dos shows" />
            <form
                onSubmit={submit}
                className="flex flex-1 flex-col gap-6 p-4"
                noValidate
            >
                <PageHeader
                    title="Checklist dos shows"
                    description="O que levar para todo show. Cada show tem a própria lista para ir marcando o que já foi separado."
                >
                    <Button variant="ghost" asChild>
                        <Link href={shows.index()}>Voltar</Link>
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        Salvar
                    </Button>
                </PageHeader>

                <Card>
                    <CardHeader>
                        <CardTitle>Itens</CardTitle>
                        <CardDescription>
                            Itens mantidos com o mesmo nome continuam marcados
                            nos shows; itens removidos saem de todos os shows.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <FormField
                            id="checklist-items"
                            label="Checklist"
                            error={form.errors.items}
                            hint={`Um item por linha, na ordem desejada · ${count} ${count === 1 ? 'item' : 'itens'}`}
                        >
                            <Textarea
                                id="checklist-items"
                                rows={16}
                                autoFocus
                                className="max-h-[40rem] min-h-60"
                                placeholder={
                                    'Capa de chuva\nProtetor de ouvido\n…'
                                }
                                value={form.data.items}
                                onChange={(event) =>
                                    form.setData('items', event.target.value)
                                }
                            />
                        </FormField>
                    </CardContent>
                </Card>
            </form>
        </>
    );
}

ShowChecklistPage.layout = {
    breadcrumbs: [
        { title: 'Shows', href: shows.index() },
        { title: 'Checklist', href: shows.checklist.edit() },
    ],
};
