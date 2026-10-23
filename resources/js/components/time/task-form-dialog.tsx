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
import { Textarea } from '@/components/ui/textarea';
import time from '@/routes/time';
import type { Task, TaskGoalType } from '@/types';

export const GOAL_LABELS: Record<TaskGoalType, string> = {
    none: 'Sem meta',
    time: 'Meta de tempo',
    outcome: 'Meta de resultado',
    time_and_outcome: 'Tempo e resultado',
};

export function TaskFormDialog({
    task,
    trigger,
}: {
    task?: Task;
    trigger: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const [goal, setGoal] = useState<TaskGoalType>(task?.goal_type ?? 'none');
    const form = task
        ? time.tasks.update.form(task.id)
        : time.tasks.store.form();
    const hasTime = goal === 'time' || goal === 'time_and_outcome';
    const hasOutcome = goal === 'outcome' || goal === 'time_and_outcome';

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {task ? 'Editar tarefa' : 'Nova tarefa'}
                    </DialogTitle>
                    <DialogDescription>
                        Renomear ou mudar a meta não altera o tempo já
                        registrado.
                    </DialogDescription>
                </DialogHeader>
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
                                id="task-title"
                                label="Título"
                                error={errors.title}
                            >
                                <Input
                                    id="task-title"
                                    name="title"
                                    required
                                    maxLength={120}
                                    defaultValue={task?.title}
                                />
                            </FormField>
                            <FormField
                                id="task-description"
                                label="Descrição (opcional)"
                                error={errors.description}
                            >
                                <Textarea
                                    id="task-description"
                                    name="description"
                                    maxLength={5000}
                                    defaultValue={task?.description ?? ''}
                                />
                            </FormField>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <FormField
                                    id="task-goal"
                                    label="Meta"
                                    error={errors.goal_type}
                                >
                                    <NativeSelect
                                        id="task-goal"
                                        name="goal_type"
                                        value={goal}
                                        onChange={(event) =>
                                            setGoal(
                                                event.target
                                                    .value as TaskGoalType,
                                            )
                                        }
                                    >
                                        {Object.entries(GOAL_LABELS).map(
                                            ([value, label]) => (
                                                <option
                                                    key={value}
                                                    value={value}
                                                >
                                                    {label}
                                                </option>
                                            ),
                                        )}
                                    </NativeSelect>
                                </FormField>
                                <FormField
                                    id="task-deadline"
                                    label="Prazo (opcional)"
                                    error={errors.deadline}
                                >
                                    <Input
                                        id="task-deadline"
                                        name="deadline"
                                        type="date"
                                        min="2000-01-01"
                                        max="2100-12-31"
                                        defaultValue={task?.deadline ?? ''}
                                    />
                                </FormField>
                            </div>
                            {hasTime && (
                                <FormField
                                    id="task-target"
                                    label="Meta de tempo"
                                    error={errors.target_minutes}
                                    hint="Calculada só com o tempo lançado na planilha."
                                >
                                    <DurationInput
                                        id="task-target"
                                        name="target_minutes"
                                        required
                                        placeholder="20h"
                                        defaultMinutes={task?.target_minutes}
                                    />
                                </FormField>
                            )}
                            {hasOutcome && (
                                <FormField
                                    id="task-outcome"
                                    label="Resultado esperado"
                                    error={errors.outcome}
                                    hint="Alcançado ao marcar a tarefa como concluída."
                                >
                                    <Input
                                        id="task-outcome"
                                        name="outcome"
                                        maxLength={255}
                                        placeholder="Ex.: concluir o curso"
                                        defaultValue={task?.outcome ?? ''}
                                    />
                                </FormField>
                            )}
                            <DialogFooter>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    Salvar
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
