import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { FormField } from '@/components/finance/form-field';
import { PageHeader } from '@/components/finance/page-header';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import shows from '@/routes/shows';
import type { Show } from '@/types';

/** Setlists are edited as text, one song per line. */
type ActForm = { band: string; setlist: string };

type ShowForm = {
    band: string;
    tour: string;
    venue: string;
    show_date: string;
    setlist: string;
    photos_url: string;
    opening_acts: ActForm[];
};

function countSongs(setlist: string): number {
    return setlist.split('\n').filter((line) => line.trim() !== '').length;
}

function SetlistField({
    id,
    value,
    error,
    onChange,
}: {
    id: string;
    value: string;
    error?: string;
    onChange: (value: string) => void;
}) {
    const count = countSongs(value);

    return (
        <FormField
            id={id}
            label="Setlist"
            error={error}
            hint={`Uma música por linha, na ordem tocada · ${count} ${count === 1 ? 'música' : 'músicas'}`}
        >
            <Textarea
                id={id}
                rows={8}
                className="max-h-[32rem] min-h-40 font-mono"
                placeholder={'Primeira música\nSegunda música\n…'}
                value={value}
                onChange={(event) => onChange(event.target.value)}
            />
        </FormField>
    );
}

export default function ShowFormPage({ show }: { show: Show | null }) {
    const form = useForm<ShowForm>({
        band: show?.band ?? '',
        tour: show?.tour ?? '',
        venue: show?.venue ?? '',
        show_date: show?.date ?? '',
        setlist: show?.setlist.join('\n') ?? '',
        photos_url: show?.photos_url ?? '',
        opening_acts:
            show?.opening_acts.map((act) => ({
                band: act.band,
                setlist: act.setlist.join('\n'),
            })) ?? [],
    });
    const errors = form.errors as Record<string, string | undefined>;
    const acts = form.data.opening_acts;

    const setActs = (next: ActForm[]) => form.setData('opening_acts', next);
    const updateAct = (index: number, patch: Partial<ActForm>) =>
        setActs(
            acts.map((act, i) => (i === index ? { ...act, ...patch } : act)),
        );
    const moveAct = (index: number, offset: number) => {
        const next = [...acts];
        [next[index], next[index + offset]] = [
            next[index + offset],
            next[index],
        ];
        setActs(next);
    };

    function submit(event: FormEvent) {
        event.preventDefault();
        form.submit(show ? shows.update(show.id) : shows.store());
    }

    const title = show ? 'Editar show' : 'Novo show';

    return (
        <>
            <Head title={title} />
            <form
                onSubmit={submit}
                className="flex flex-1 flex-col gap-6 p-4"
                noValidate
            >
                <PageHeader title={title}>
                    <Button variant="ghost" asChild>
                        <Link href={show ? shows.show(show.id) : shows.index()}>
                            Cancelar
                        </Link>
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        Salvar
                    </Button>
                </PageHeader>

                <Card>
                    <CardHeader>
                        <CardTitle>Show</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 md:grid-cols-2">
                        <FormField
                            id="show-band"
                            label="Banda"
                            error={errors.band}
                        >
                            <Input
                                id="show-band"
                                required
                                maxLength={120}
                                autoFocus={!show}
                                value={form.data.band}
                                onChange={(event) =>
                                    form.setData('band', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="show-tour"
                            label="Nome da tour (opcional)"
                            error={errors.tour}
                        >
                            <Input
                                id="show-tour"
                                maxLength={160}
                                value={form.data.tour}
                                onChange={(event) =>
                                    form.setData('tour', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="show-venue"
                            label="Local"
                            error={errors.venue}
                        >
                            <Input
                                id="show-venue"
                                required
                                maxLength={160}
                                placeholder="Ex.: Allianz Parque"
                                value={form.data.venue}
                                onChange={(event) =>
                                    form.setData('venue', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="show-date"
                            label="Data"
                            error={errors.show_date}
                        >
                            <Input
                                id="show-date"
                                type="date"
                                required
                                min="2000-01-01"
                                max="2100-12-31"
                                value={form.data.show_date}
                                onChange={(event) =>
                                    form.setData(
                                        'show_date',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <FormField
                            id="show-photos"
                            label="Álbum do Google Fotos (opcional)"
                            error={errors.photos_url}
                            hint="Cole o link de compartilhamento do álbum."
                            className="md:col-span-2"
                        >
                            <Input
                                id="show-photos"
                                type="url"
                                inputMode="url"
                                maxLength={2048}
                                placeholder="https://photos.app.goo.gl/…"
                                value={form.data.photos_url}
                                onChange={(event) =>
                                    form.setData(
                                        'photos_url',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <div className="md:col-span-2">
                            <SetlistField
                                id="show-setlist"
                                value={form.data.setlist}
                                error={
                                    errors.setlist ??
                                    Object.entries(errors).find(([key]) =>
                                        key.startsWith('setlist.'),
                                    )?.[1]
                                }
                                onChange={(value) =>
                                    form.setData('setlist', value)
                                }
                            />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-start justify-between gap-3">
                        <div className="space-y-1.5">
                            <CardTitle>Shows de abertura</CardTitle>
                            <CardDescription>
                                Opcional. Na ordem em que tocaram.
                            </CardDescription>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={acts.length >= 10}
                            onClick={() =>
                                setActs([...acts, { band: '', setlist: '' }])
                            }
                        >
                            <Plus />
                            Adicionar abertura
                        </Button>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        {acts.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                Nenhum show de abertura.
                            </p>
                        )}
                        <InputError message={errors.opening_acts} />
                        {acts.map((act, index) => (
                            <fieldset
                                key={index}
                                className="space-y-4 rounded-lg border p-4"
                            >
                                <div className="flex items-end gap-2">
                                    <FormField
                                        id={`act-${index}-band`}
                                        label={`Banda de abertura ${index + 1}`}
                                        error={
                                            errors[`opening_acts.${index}.band`]
                                        }
                                        className="flex-1"
                                    >
                                        <Input
                                            id={`act-${index}-band`}
                                            required
                                            maxLength={120}
                                            value={act.band}
                                            onChange={(event) =>
                                                updateAct(index, {
                                                    band: event.target.value,
                                                })
                                            }
                                        />
                                    </FormField>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Mover para cima"
                                        disabled={index === 0}
                                        onClick={() => moveAct(index, -1)}
                                    >
                                        <ArrowUp />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Mover para baixo"
                                        disabled={index === acts.length - 1}
                                        onClick={() => moveAct(index, 1)}
                                    >
                                        <ArrowDown />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Remover abertura ${index + 1}`}
                                        onClick={() =>
                                            setActs(
                                                acts.filter(
                                                    (_, i) => i !== index,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                                <SetlistField
                                    id={`act-${index}-setlist`}
                                    value={act.setlist}
                                    error={
                                        Object.entries(errors).find(([key]) =>
                                            key.startsWith(
                                                `opening_acts.${index}.setlist`,
                                            ),
                                        )?.[1]
                                    }
                                    onChange={(value) =>
                                        updateAct(index, { setlist: value })
                                    }
                                />
                            </fieldset>
                        ))}
                    </CardContent>
                </Card>

                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        Salvar show
                    </Button>
                </div>
            </form>
        </>
    );
}

ShowFormPage.layout = {
    breadcrumbs: [
        { title: 'Shows', href: shows.index() },
        { title: 'Cadastro', href: shows.create() },
    ],
};
