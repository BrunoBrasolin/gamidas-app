import { Form } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { FormField } from '@/components/finance/form-field';
import { DurationInput } from '@/components/time/duration-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { today } from '@/lib/format';
import time from '@/routes/time';
import type { EntryDraft, TaskOption, TimeEntry } from '@/types';

/**
 * Create or edit a manual time entry. A draft (for instance copied from a
 * timer session) only pre-fills the form: nothing is saved until the user
 * submits it.
 */
export function EntryFormDialog({
    tasks,
    entry,
    draft,
    trigger,
    open: controlledOpen,
    onOpenChange,
}: {
    tasks: TaskOption[];
    entry?: TimeEntry;
    draft?: EntryDraft;
    trigger?: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
}) {
    const [localOpen, setLocalOpen] = useState(false);
    const open = controlledOpen ?? localOpen;
    const setOpen = onOpenChange ?? setLocalOpen;
    const form = entry
        ? time.entries.update.form(entry.id)
        : time.entries.store.form();
    const selectedTask = entry?.task_id ?? draft?.task_id;
    // Archived tasks only appear when the entry already belongs to one.
    const options = tasks.filter(
        (task) => task.status !== 'archived' || task.id === entry?.task_id,
    );

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            {trigger && <DialogTrigger asChild>{trigger}</DialogTrigger>}
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {entry ? 'Editar lançamento' : 'Lançar tempo'}
                    </DialogTitle>
                    <DialogDescription>
                        Tempo registrado manualmente na planilha.
                        {draft?.minutes !== undefined &&
                            ' Valores sugeridos a partir do cronômetro: revise antes de salvar.'}
                    </DialogDescription>
                </DialogHeader>
                {options.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Crie uma tarefa antes de lançar tempo.
                    </p>
                ) : (
                    <Form
                        {...form}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setOpen(false)}
                        resetOnSuccess
                        className="space-y-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <FormField
                                    id="entry-task"
                                    label="Tarefa"
                                    error={errors.task_id}
                                >
                                    <NativeSelect
                                        id="entry-task"
                                        name="task_id"
                                        required
                                        defaultValue={selectedTask ?? ''}
                                    >
                                        <option value="" disabled>
                                            Selecione…
                                        </option>
                                        {options.map((task) => (
                                            <option
                                                key={task.id}
                                                value={task.id}
                                            >
                                                {task.title}
                                                {task.status === 'completed'
                                                    ? ' (concluída)'
                                                    : task.status === 'archived'
                                                      ? ' (arquivada)'
                                                      : ''}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </FormField>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <FormField
                                        id="entry-date"
                                        label="Data"
                                        error={errors.entry_date}
                                    >
                                        <Input
                                            id="entry-date"
                                            name="entry_date"
                                            type="date"
                                            required
                                            min="2000-01-01"
                                            max="2100-12-31"
                                            defaultValue={
                                                entry?.date ??
                                                draft?.date ??
                                                today()
                                            }
                                        />
                                    </FormField>
                                    <FormField
                                        id="entry-minutes"
                                        label="Duração"
                                        error={errors.minutes}
                                    >
                                        <DurationInput
                                            id="entry-minutes"
                                            name="minutes"
                                            required
                                            defaultMinutes={
                                                entry?.minutes ?? draft?.minutes
                                            }
                                        />
                                    </FormField>
                                </div>
                                <FormField
                                    id="entry-description"
                                    label="Descrição (opcional)"
                                    error={errors.description}
                                >
                                    <Input
                                        id="entry-description"
                                        name="description"
                                        maxLength={255}
                                        defaultValue={
                                            entry?.description ??
                                            draft?.description ??
                                            ''
                                        }
                                    />
                                </FormField>
                                <DialogFooter>
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        Salvar
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                )}
            </DialogContent>
        </Dialog>
    );
}
