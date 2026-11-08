import { Head, Link, useForm } from '@inertiajs/react';
import { FileText, Plus, Undo2, X } from 'lucide-react';
import type { FormEvent } from 'react';
import { useRef } from 'react';
import {
    attachmentKindAccept,
    attachmentKindLabels,
    attachmentKinds,
} from '@/components/complementary-hours/attachment-kinds';
import { FormField } from '@/components/finance/form-field';
import { PageHeader } from '@/components/finance/page-header';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { formatFileSize, hoursInput } from '@/lib/hours';
import { cn } from '@/lib/utils';
import complementaryHours from '@/routes/complementary-hours';
import type {
    ComplementaryActivity,
    ComplementaryAttachmentKind,
} from '@/types';

type NewAttachment = { kind: ComplementaryAttachmentKind; file: File };

type ActivityForm = {
    title: string;
    completed_on: string;
    hours: string;
    report_done: boolean;
    attachments: NewAttachment[];
    remove_attachments: number[];
};

export default function ComplementaryActivityFormPage({
    activity,
    maxAttachmentMb,
    maxUploads,
}: {
    activity: ComplementaryActivity | null;
    maxAttachmentMb: number;
    maxUploads: number;
    maxAttachments: number;
}) {
    const form = useForm<ActivityForm>({
        title: activity?.title ?? '',
        completed_on: activity?.completed_on ?? '',
        hours: activity ? hoursInput(activity.minutes) : '',
        report_done: activity?.report_done ?? false,
        attachments: [],
        remove_attachments: [],
    });
    const fileInput = useRef<HTMLInputElement>(null);
    const pendingKind = useRef<ComplementaryAttachmentKind>('certificate');
    // Errors of nested fields come keyed by path ("attachments.0.file").
    const errors = form.errors as Record<string, string | undefined>;

    function pickFiles(kind: ComplementaryAttachmentKind) {
        pendingKind.current = kind;

        if (fileInput.current) {
            fileInput.current.accept = attachmentKindAccept[kind];
            fileInput.current.click();
        }
    }

    function addFiles(files: FileList | null) {
        const kind = pendingKind.current;
        const added = Array.from(files ?? []).map((file) => ({ kind, file }));

        if (added.length === 0) {
            return;
        }

        form.setData((data) => ({
            ...data,
            attachments: [...data.attachments, ...added],
            // Attaching the filled-in form usually means it is done.
            report_done: data.report_done || kind === 'report',
        }));
        form.clearErrors();
    }

    function setNewKind(index: number, kind: ComplementaryAttachmentKind) {
        form.setData(
            'attachments',
            form.data.attachments.map((attachment, i) =>
                i === index ? { ...attachment, kind } : attachment,
            ),
        );
    }

    function removeNew(index: number) {
        form.setData(
            'attachments',
            form.data.attachments.filter((_, i) => i !== index),
        );
        form.clearErrors();
    }

    function toggleExisting(id: number) {
        const removing = form.data.remove_attachments.includes(id);

        form.setData(
            'remove_attachments',
            removing
                ? form.data.remove_attachments.filter((other) => other !== id)
                : [...form.data.remove_attachments, id],
        );
    }

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
    const existing = activity?.attachments ?? [];
    const nothingAttached =
        form.data.attachments.length === 0 &&
        existing.every((attachment) =>
            form.data.remove_attachments.includes(attachment.id),
        );

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
                        <div className="flex items-start gap-3 md:col-span-4">
                            <Checkbox
                                id="activity-report-done"
                                checked={form.data.report_done}
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'report_done',
                                        checked === true,
                                    )
                                }
                            />
                            <div className="grid gap-1">
                                <Label htmlFor="activity-report-done">
                                    Relatório preenchido
                                </Label>
                                <p className="text-xs text-muted-foreground">
                                    Marque quando o relatório da faculdade
                                    estiver pronto. Anexe-o abaixo como
                                    “Relatório”.
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Anexos</CardTitle>
                        <CardDescription>
                            Certificado (obrigatório), relatório e outros
                            arquivos. PDF ou imagem; relatório e outros também
                            aceitam Word, Excel e PowerPoint. Até{' '}
                            {maxAttachmentMb} MB por arquivo e {maxUploads} por
                            envio.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-4">
                        {(existing.length > 0 ||
                            form.data.attachments.length > 0) && (
                            <ul className="grid gap-2">
                                {existing.map((attachment) => {
                                    const removing =
                                        form.data.remove_attachments.includes(
                                            attachment.id,
                                        );

                                    return (
                                        <li
                                            key={`existing-${attachment.id}`}
                                            className={cn(
                                                'flex items-center gap-3 rounded-md border px-3 py-2',
                                                removing && 'bg-muted/50',
                                            )}
                                        >
                                            <FileText
                                                className="size-4 shrink-0 text-muted-foreground"
                                                aria-hidden
                                            />
                                            <div className="min-w-0 flex-1">
                                                <p
                                                    className={cn(
                                                        'truncate text-sm',
                                                        removing &&
                                                            'text-muted-foreground line-through',
                                                    )}
                                                >
                                                    {attachment.name}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {removing
                                                        ? 'Será removido ao salvar'
                                                        : formatFileSize(
                                                              attachment.size,
                                                          )}
                                                </p>
                                            </div>
                                            <Badge variant="outline">
                                                {
                                                    attachmentKindLabels[
                                                        attachment.kind
                                                    ]
                                                }
                                            </Badge>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label={
                                                    removing
                                                        ? `Manter ${attachment.name}`
                                                        : `Remover ${attachment.name}`
                                                }
                                                onClick={() =>
                                                    toggleExisting(
                                                        attachment.id,
                                                    )
                                                }
                                            >
                                                {removing ? <Undo2 /> : <X />}
                                            </Button>
                                        </li>
                                    );
                                })}
                                {form.data.attachments.map(
                                    (attachment, index) => (
                                        <li
                                            key={`new-${index}-${attachment.file.name}`}
                                            className="grid gap-1"
                                        >
                                            <div className="flex flex-wrap items-center gap-3 rounded-md border border-dashed px-3 py-2">
                                                <FileText
                                                    className="size-4 shrink-0 text-muted-foreground"
                                                    aria-hidden
                                                />
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-sm">
                                                        {attachment.file.name}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">
                                                        Novo ·{' '}
                                                        {formatFileSize(
                                                            attachment.file
                                                                .size,
                                                        )}
                                                    </p>
                                                </div>
                                                <NativeSelect
                                                    aria-label={`Tipo de ${attachment.file.name}`}
                                                    className="w-36"
                                                    value={attachment.kind}
                                                    onChange={(event) =>
                                                        setNewKind(
                                                            index,
                                                            event.target
                                                                .value as ComplementaryAttachmentKind,
                                                        )
                                                    }
                                                >
                                                    {attachmentKinds.map(
                                                        (kind) => (
                                                            <option
                                                                key={kind}
                                                                value={kind}
                                                            >
                                                                {
                                                                    attachmentKindLabels[
                                                                        kind
                                                                    ]
                                                                }
                                                            </option>
                                                        ),
                                                    )}
                                                </NativeSelect>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Tirar ${attachment.file.name}`}
                                                    onClick={() =>
                                                        removeNew(index)
                                                    }
                                                >
                                                    <X />
                                                </Button>
                                            </div>
                                            <InputError
                                                message={
                                                    errors[
                                                        `attachments.${index}.file`
                                                    ] ??
                                                    errors[
                                                        `attachments.${index}.kind`
                                                    ]
                                                }
                                            />
                                        </li>
                                    ),
                                )}
                            </ul>
                        )}

                        {nothingAttached && (
                            <p className="text-sm text-muted-foreground">
                                Nenhum arquivo anexado ainda.
                            </p>
                        )}

                        <div className="flex flex-wrap gap-2">
                            {attachmentKinds.map((kind) => (
                                <Button
                                    key={kind}
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => pickFiles(kind)}
                                >
                                    <Plus />
                                    {attachmentKindLabels[kind]}
                                </Button>
                            ))}
                            <input
                                ref={fileInput}
                                type="file"
                                multiple
                                hidden
                                onChange={(event) => {
                                    addFiles(event.target.files);
                                    event.target.value = '';
                                }}
                            />
                        </div>
                        <InputError message={errors.attachments} />
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
