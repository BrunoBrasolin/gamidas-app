import { useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';
import { FormField } from '@/components/finance/form-field';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { hoursInput } from '@/lib/hours';
import complementaryHours from '@/routes/complementary-hours';

/**
 * Sets how many complementary hours the user's course requires.
 */
export function GoalDialog({
    requiredMinutes,
    trigger,
}: {
    requiredMinutes: number | null;
    trigger: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        hours: requiredMinutes === null ? '' : hoursInput(requiredMinutes),
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.submit(complementaryHours.goal.update(), {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (!next) {
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="sm:max-w-sm">
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <DialogHeader>
                        <DialogTitle>Meta de horas</DialogTitle>
                        <DialogDescription>
                            O total de horas complementares exigido pelo seu
                            curso.
                        </DialogDescription>
                    </DialogHeader>
                    <FormField
                        id="goal-hours"
                        label="Horas necessárias"
                        error={form.errors.hours}
                        hint="Ex.: 200 ou 120:30"
                    >
                        <Input
                            id="goal-hours"
                            inputMode="decimal"
                            autoFocus
                            maxLength={12}
                            value={form.data.hours}
                            onChange={(event) =>
                                form.setData('hours', event.target.value)
                            }
                        />
                    </FormField>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Salvar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
