import { Head, Link, useForm } from '@inertiajs/react';
import { Eye, Paperclip, Pencil } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { FormField } from '@/components/finance/form-field';
import { PageHeader } from '@/components/finance/page-header';
import { MarkdownView } from '@/components/time/markdown';
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
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatFileSize, hoursInput } from '@/lib/hours';
import complementaryHours from '@/routes/complementary-hours';
import type { ComplementaryActivity } from '@/types';

type ActivityForm = {
    title: string;
    completed_on: string;
    hours: string;
    certificate: File | null;
    report: string;
};

export default function ComplementaryActivityFormPage({
    activity,
    maxCertificateMb,
}: {
    activity: ComplementaryActivity | null;
    maxCertificateMb: number;
}) {
    const form = useForm<ActivityForm>({
        title: activity?.title ?? '',
        completed_on: activity?.completed_on ?? '',
        hours: activity ? hoursInput(activity.minutes) : '',
        certificate: null,
        report: activity?.report ?? '',
    });
    const [preview, setPreview] = useState(false);

    function submit(event: FormEvent) {
        event.preventDefault();

        if (activity) {
            // PHP only reads files from POST bodies, so the update is spoofed.
            form.transform((data) => ({ ...data, _method: 'put' }));
            form.post(complementaryHours.update.url(activity.id), {
                forceFormData: true,
            });
        } else {
            form.transform((data) => data);
            form.post(complementaryHours.store.url(), { forceFormData: true });
        }
    }

    const title = activity ? 'Editar atividade' : 'Nova atividade';

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
                        <Link
                            href={
                                activity
                                    ? complementaryHours.show(activity.id)
                                    : complementaryHours.index()
                            }
                        >
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
                        <CardTitle>Atividade</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 md:grid-cols-4">
                        <FormField
                            id="activity-title"
                            label="Título"
                            error={form.errors.title}
                            className="md:col-span-2"
                        >
                            <Input
                                id="activity-title"
                                required
                                maxLength={160}
                                autoFocus={!activity}
                                placeholder="Ex.: Semana de Tecnologia"
                                value={form.data.title}
                                onChange={(event) =>
                                    form.setData('title', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="activity-date"
                            label="Data (opcional)"
                            error={form.errors.completed_on}
                        >
                            <Input
                                id="activity-date"
                                type="date"
                                min="2000-01-01"
                                max="2100-12-31"
                                value={form.data.completed_on}
                                onChange={(event) =>
                                    form.setData(
                                        'completed_on',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <FormField
                            id="activity-hours"
                            label="Horas"
                            error={form.errors.hours}
                            hint="Ex.: 4, 1,5 ou 1:30"
                        >
                            <Input
                                id="activity-hours"
                                required
                                inputMode="decimal"
                                maxLength={12}
                                value={form.data.hours}
                                onChange={(event) =>
                                    form.setData('hours', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="activity-certificate"
                            label={
                                activity
                                    ? 'Substituir certificado (opcional)'
                                    : 'Certificado'
                            }
                            error={form.errors.certificate}
                            hint={
                                activity ? (
                                    <span className="flex items-center gap-1.5">
                                        <Paperclip
                                            className="size-3.5"
                                            aria-hidden
                                        />
                                        Atual: {activity.certificate.name} (
                                        {formatFileSize(
                                            activity.certificate.size,
                                        )}
                                        )
                                    </span>
                                ) : (
                                    `PDF ou imagem, até ${maxCertificateMb} MB.`
                                )
                            }
                            className="md:col-span-4"
                        >
                            <Input
                                id="activity-certificate"
                                type="file"
                                required={!activity}
                                accept="application/pdf,image/jpeg,image/png,image/webp,image/gif"
                                onChange={(event) =>
                                    form.setData(
                                        'certificate',
                                        event.target.files?.[0] ?? null,
                                    )
                                }
                            />
                        </FormField>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-start justify-between gap-4">
                        <div className="space-y-1.5">
                            <CardTitle>Relatório (opcional)</CardTitle>
                            <CardDescription>
                                Escrito em Markdown; pode ser exportado em PDF
                                depois de salvo.
                            </CardDescription>
                        </div>
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            size="sm"
                            value={preview ? 'preview' : 'write'}
                            onValueChange={(value) =>
                                value && setPreview(value === 'preview')
                            }
                        >
                            <ToggleGroupItem
                                value="write"
                                aria-label="Escrever"
                                className="px-3"
                            >
                                <Pencil />
                                <span className="hidden sm:inline">
                                    Escrever
                                </span>
                            </ToggleGroupItem>
                            <ToggleGroupItem
                                value="preview"
                                aria-label="Visualizar"
                                className="px-3"
                            >
                                <Eye />
                                <span className="hidden sm:inline">
                                    Visualizar
                                </span>
                            </ToggleGroupItem>
                        </ToggleGroup>
                    </CardHeader>
                    <CardContent className="grid gap-1.5">
                        {preview ? (
                            form.data.report.trim() === '' ? (
                                <p className="text-sm text-muted-foreground">
                                    Nada para visualizar ainda.
                                </p>
                            ) : (
                                <MarkdownView
                                    content={form.data.report}
                                    className="min-h-64 rounded-md border px-4 py-3"
                                />
                            )
                        ) : (
                            <Textarea
                                id="activity-report"
                                aria-label="Relatório"
                                rows={16}
                                className="min-h-64 font-mono text-sm"
                                placeholder={
                                    '## Objetivo\n\nO que foi a atividade…\n\n## O que aprendi\n\n- …'
                                }
                                value={form.data.report}
                                onChange={(event) =>
                                    form.setData('report', event.target.value)
                                }
                            />
                        )}
                        {form.errors.report && (
                            <p className="text-sm text-destructive">
                                {form.errors.report}
                            </p>
                        )}
                    </CardContent>
                </Card>
            </form>
        </>
    );
}

ComplementaryActivityFormPage.layout = {
    breadcrumbs: [
        { title: 'Horas complementares', href: complementaryHours.index() },
        { title: 'Atividade', href: complementaryHours.index() },
    ],
};
