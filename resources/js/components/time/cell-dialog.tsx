import { router } from '@inertiajs/react';
import { useState } from 'react';
import { MarkdownView } from '@/components/time/markdown';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatDate } from '@/lib/format';
import { formatClock, formatDuration, parseClock, weekday } from '@/lib/time';
import time from '@/routes/time';
import type { TimeCell } from '@/types';

export type CellTarget = {
    categoryId: number;
    categoryName: string;
    date: string;
    cell: TimeCell | undefined;
};

/**
 * Edits one sheet cell: the time worked on a day for a category (typed as
 * 7:42:14, 1:30 or 45m) and a Markdown note that can be changed any time.
 */
export function CellDialog({
    target,
    onClose,
}: {
    target: CellTarget | null;
    onClose: () => void;
}) {
    return (
        <Dialog
            open={target !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="sm:max-w-xl">
                {target && (
                    <CellForm
                        key={`${target.categoryId}|${target.date}`}
                        target={target}
                        onClose={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function CellForm({
    target,
    onClose,
}: {
    target: CellTarget;
    onClose: () => void;
}) {
    const [clock, setClock] = useState(
        target.cell && target.cell.seconds > 0
            ? formatClock(target.cell.seconds)
            : '',
    );
    const [note, setNote] = useState(target.cell?.note ?? '');
    const [preview, setPreview] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const seconds = parseClock(clock);

    function save(data: { seconds: number; note: string }) {
        router.put(
            time.logs.update.url(),
            {
                category_id: target.categoryId,
                log_date: target.date,
                ...data,
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onError: setErrors,
                onSuccess: onClose,
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <form
            className="space-y-4"
            onSubmit={(event) => {
                event.preventDefault();

                if (seconds !== null) {
                    save({ seconds, note });
                }
            }}
        >
            <DialogHeader>
                <DialogTitle>{target.categoryName}</DialogTitle>
                <DialogDescription>
                    {weekday(target.date)}, {formatDate(target.date)}
                </DialogDescription>
            </DialogHeader>

            <div className="grid gap-1.5">
                <Label htmlFor="cell-time">Horas trabalhadas</Label>
                <Input
                    id="cell-time"
                    autoFocus
                    autoComplete="off"
                    placeholder="7:42:14"
                    value={clock}
                    aria-invalid={seconds === null || !!errors.seconds}
                    onChange={(event) => setClock(event.target.value)}
                />
                <p className="text-xs text-muted-foreground" aria-live="polite">
                    {seconds === null
                        ? 'Use 7:42:14, 1:30, 1h30, 45m ou 1,5h.'
                        : seconds > 0
                          ? `= ${formatDuration(seconds)}`
                          : 'Deixe vazio para não registrar horas.'}
                </p>
                {errors.seconds && (
                    <p className="text-xs text-destructive">{errors.seconds}</p>
                )}
            </div>

            <div className="grid gap-1.5">
                <div className="flex items-center justify-between">
                    <Label htmlFor="cell-note">Nota (Markdown)</Label>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => setPreview((value) => !value)}
                    >
                        {preview ? 'Editar' : 'Visualizar'}
                    </Button>
                </div>
                {preview ? (
                    <div className="max-h-72 min-h-40 overflow-auto rounded-md border p-3 text-sm">
                        {note.trim() === '' ? (
                            <p className="text-muted-foreground">Sem nota.</p>
                        ) : (
                            <MarkdownView content={note} />
                        )}
                    </div>
                ) : (
                    <Textarea
                        id="cell-note"
                        className="max-h-72 min-h-40 font-mono text-sm"
                        placeholder={
                            '- Reunião com o cliente\n- Ajustes no template'
                        }
                        value={note}
                        onChange={(event) => setNote(event.target.value)}
                    />
                )}
                {errors.note && (
                    <p className="text-xs text-destructive">{errors.note}</p>
                )}
                {errors.category && (
                    <p className="text-xs text-destructive">
                        {errors.category}
                    </p>
                )}
            </div>

            <DialogFooter className="gap-2">
                {target.cell && (
                    <Button
                        type="button"
                        variant="outline"
                        className="sm:mr-auto"
                        disabled={processing}
                        onClick={() => save({ seconds: 0, note: '' })}
                    >
                        Limpar
                    </Button>
                )}
                <Button type="submit" disabled={processing || seconds === null}>
                    {processing && <Spinner />}
                    Salvar
                </Button>
            </DialogFooter>
        </form>
    );
}
