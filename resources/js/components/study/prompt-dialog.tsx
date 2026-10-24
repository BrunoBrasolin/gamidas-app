import { router } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

/**
 * Asks for a single title (new chapter, rename a page...) and sends it with
 * the given method. Controlled, so it can be opened from a menu item.
 */
export function PromptDialog({
    open,
    onOpenChange,
    title,
    description,
    label,
    initialValue = '',
    submitLabel = 'Salvar',
    method,
    url,
    extra,
    onSuccess,
    maxLength = 160,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description?: ReactNode;
    label: string;
    initialValue?: string;
    submitLabel?: string;
    method: 'post' | 'put';
    url: string;
    extra?: Record<string, string | number | null>;
    onSuccess?: () => void;
    maxLength?: number;
}) {
    const [value, setValue] = useState(initialValue);
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);

    function submit(event: FormEvent) {
        event.preventDefault();

        router.visit(url, {
            method,
            data: { title: value, ...extra },
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onError: (errors) =>
                setError(errors.title ?? Object.values(errors)[0]),
            onSuccess: () => {
                onOpenChange(false);
                onSuccess?.();
            },
            onFinish: () => setProcessing(false),
        });
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (next) {
                    setValue(initialValue);
                    setError(undefined);
                }

                onOpenChange(next);
            }}
        >
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogTitle>{title}</DialogTitle>
                    {description && (
                        <DialogDescription>{description}</DialogDescription>
                    )}
                    <div className="grid gap-1.5">
                        <Label htmlFor="prompt-value">{label}</Label>
                        <Input
                            id="prompt-value"
                            value={value}
                            maxLength={maxLength}
                            autoFocus
                            className="h-11 text-base"
                            onChange={(event) => setValue(event.target.value)}
                        />
                        <InputError message={error} />
                    </div>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary" className="h-11">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            className="h-11"
                            disabled={processing || value.trim() === ''}
                        >
                            {processing && <Spinner />}
                            {submitLabel}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
