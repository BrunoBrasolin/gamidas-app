import { useForm } from '@inertiajs/react';
import { Check, ImagePlus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import { FormField } from '@/components/finance/form-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { STUDY_COLOR_KEYS, STUDY_COLORS } from '@/lib/study';
import { cn } from '@/lib/utils';
import study from '@/routes/study';
import type { StudyColor, StudySubject } from '@/types';
import { BookCover } from './book-cover';

type SubjectForm = {
    title: string;
    description: string;
    accent_color: StudyColor;
    cover: File | null;
    remove_cover: boolean;
    _method?: 'put';
};

/**
 * Create or edit a subject: title, description, color and cover image, with
 * a live preview of the book.
 */
export function SubjectFormDialog({
    open,
    onOpenChange,
    subject,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    subject?: StudySubject | null;
}) {
    const form = useForm<SubjectForm>({
        title: subject?.title ?? '',
        description: subject?.description ?? '',
        accent_color: subject?.accent_color ?? 'sage',
        cover: null,
        remove_cover: false,
    });
    const [preview, setPreview] = useState<string | null>(null);

    useEffect(() => {
        if (!form.data.cover) {
            setPreview(null);

            return;
        }

        const url = URL.createObjectURL(form.data.cover);
        setPreview(url);

        return () => URL.revokeObjectURL(url);
    }, [form.data.cover]);

    const coverUrl =
        preview ?? (form.data.remove_cover ? null : (subject?.cover_url ?? null));

    function submit(event: FormEvent) {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                onOpenChange(false);

                if (!subject) {
                    form.reset();
                }
            },
        };

        if (subject) {
            // Files only travel in POST bodies; Laravel reads the PUT from _method.
            form.transform((data) => ({ ...data, _method: 'put' as const }));
            form.post(study.subjects.update.url(subject.id), options);
        } else {
            form.post(study.subjects.store.url(), options);
        }
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (next) {
                    form.clearErrors();
                }

                onOpenChange(next);
            }}
        >
            <DialogContent className="max-h-[92dvh] overflow-y-auto sm:max-w-2xl">
                <form onSubmit={submit} className="grid gap-5">
                    <div className="space-y-1">
                        <DialogTitle>
                            {subject ? 'Editar matéria' : 'Nova matéria'}
                        </DialogTitle>
                        <DialogDescription>
                            Cada matéria é um livro da sua biblioteca, com
                            capítulos e páginas.
                        </DialogDescription>
                    </div>

                    <div className="grid gap-5 sm:grid-cols-[10rem_1fr]">
                        <div className="mx-auto w-32 space-y-2 sm:w-full">
                            <BookCover
                                title={form.data.title || 'Sem título'}
                                color={form.data.accent_color}
                                coverUrl={coverUrl}
                            />
                            <div className="grid gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-10"
                                    asChild
                                >
                                    <label className="cursor-pointer">
                                        <ImagePlus />
                                        {coverUrl ? 'Trocar capa' : 'Pôr capa'}
                                        <input
                                            type="file"
                                            accept="image/jpeg,image/png,image/webp,image/gif"
                                            className="sr-only"
                                            onChange={(event) => {
                                                const file =
                                                    event.target.files?.[0] ??
                                                    null;
                                                form.setData((data) => ({
                                                    ...data,
                                                    cover: file,
                                                    remove_cover: false,
                                                }));
                                                event.target.value = '';
                                            }}
                                        />
                                    </label>
                                </Button>
                                {coverUrl && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        className="h-10"
                                        onClick={() =>
                                            form.setData((data) => ({
                                                ...data,
                                                cover: null,
                                                remove_cover: true,
                                            }))
                                        }
                                    >
                                        <Trash2 />
                                        Tirar capa
                                    </Button>
                                )}
                            </div>
                            <InputError message={form.errors.cover} />
                        </div>

                        <div className="grid content-start gap-4">
                            <FormField
                                id="subject-title"
                                label="Título"
                                error={form.errors.title}
                            >
                                <Input
                                    id="subject-title"
                                    value={form.data.title}
                                    maxLength={160}
                                    required
                                    autoFocus={!subject}
                                    placeholder="Ex.: Biologia"
                                    className="h-11 text-base"
                                    onChange={(event) =>
                                        form.setData('title', event.target.value)
                                    }
                                />
                            </FormField>
                            <FormField
                                id="subject-description"
                                label="Descrição (opcional)"
                                error={form.errors.description}
                            >
                                <Textarea
                                    id="subject-description"
                                    value={form.data.description}
                                    maxLength={1000}
                                    rows={3}
                                    className="text-base"
                                    onChange={(event) =>
                                        form.setData(
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                />
                            </FormField>
                            <fieldset className="grid gap-2">
                                <legend className="mb-2 text-sm font-medium">
                                    Cor da capa
                                </legend>
                                <div className="flex flex-wrap gap-2">
                                    {STUDY_COLOR_KEYS.map((color) => {
                                        const selected =
                                            form.data.accent_color === color;

                                        return (
                                            <button
                                                key={color}
                                                type="button"
                                                aria-pressed={selected}
                                                aria-label={
                                                    STUDY_COLORS[color].label
                                                }
                                                title={STUDY_COLORS[color].label}
                                                onClick={() =>
                                                    form.setData(
                                                        'accent_color',
                                                        color,
                                                    )
                                                }
                                                className={cn(
                                                    'flex size-11 items-center justify-center rounded-full ring-offset-2 ring-offset-background outline-none focus-visible:ring-[3px] focus-visible:ring-ring',
                                                    selected &&
                                                        'ring-2 ring-foreground',
                                                )}
                                                style={{
                                                    background: `linear-gradient(155deg, ${STUDY_COLORS[color].light}, ${STUDY_COLORS[color].dark})`,
                                                }}
                                            >
                                                {selected && (
                                                    <Check
                                                        className="size-5 text-white"
                                                        aria-hidden
                                                    />
                                                )}
                                            </button>
                                        );
                                    })}
                                </div>
                                <InputError message={form.errors.accent_color} />
                            </fieldset>
                        </div>
                    </div>

                    {form.progress && (
                        <progress
                            className="h-1 w-full"
                            value={form.progress.percentage}
                            max={100}
                        />
                    )}

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button
                                type="button"
                                variant="secondary"
                                className="h-11"
                            >
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            className="h-11"
                            disabled={form.processing}
                        >
                            {form.processing && <Spinner />}
                            {subject ? 'Salvar' : 'Criar matéria'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
