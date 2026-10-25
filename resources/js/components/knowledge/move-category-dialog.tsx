import { router } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { indent } from '@/components/knowledge/move-page-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { MAX_CATEGORY_DEPTH } from '@/lib/knowledge';
import type { KnowledgeCategoryEntry } from '@/lib/knowledge';
import knowledge from '@/routes/knowledge';

/**
 * Put a category (with its pages and subcategories) inside another category
 * of the same subject, or at the top level. Itself, its own subcategories and
 * places that would nest too deep are not offered.
 */
export function MoveCategoryDialog({
    entry,
    entries,
    onOpenChange,
}: {
    entry: KnowledgeCategoryEntry;
    entries: KnowledgeCategoryEntry[];
    onOpenChange: (open: boolean) => void;
}) {
    const { category } = entry;
    const [parentId, setParentId] = useState<number | null>(category.parent_id);
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);
    const options = entries.filter(
        (candidate) =>
            candidate.category.id !== category.id &&
            !entry.descendants.includes(candidate.category.id) &&
            candidate.depth + 1 + entry.height <= MAX_CATEGORY_DEPTH,
    );

    function submit() {
        router.put(
            knowledge.categories.move.url(category.id),
            { parent_id: parentId },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onError: (errors) =>
                    setError(errors.parent_id ?? Object.values(errors)[0]),
                onSuccess: () => onOpenChange(false),
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <Dialog open onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogTitle>Mover “{category.title}”</DialogTitle>
                <DialogDescription>
                    A categoria vai para o fim da escolhida, com as páginas e
                    subcategorias dela.
                </DialogDescription>
                <div className="grid gap-1.5">
                    <Label htmlFor="move-parent">Dentro de</Label>
                    <NativeSelect
                        id="move-parent"
                        className="h-11 text-base"
                        value={parentId ?? ''}
                        onChange={(event) =>
                            setParentId(
                                event.target.value === ''
                                    ? null
                                    : Number(event.target.value),
                            )
                        }
                    >
                        <option value="">Nenhuma (primeiro nível)</option>
                        {options.map(({ category: option, depth }) => (
                            <option key={option.id} value={option.id}>
                                {indent(depth)}
                                {option.title}
                            </option>
                        ))}
                    </NativeSelect>
                    <InputError message={error} />
                </div>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary" className="h-11">
                            Cancelar
                        </Button>
                    </DialogClose>
                    <Button
                        className="h-11"
                        disabled={processing || parentId === category.parent_id}
                        onClick={submit}
                    >
                        {processing && <Spinner />}
                        Mover
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
