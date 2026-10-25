import { router } from '@inertiajs/react';
import { useState } from 'react';
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
import { flattenCategories } from '@/lib/knowledge';
import knowledge from '@/routes/knowledge';
import type { KnowledgeDestination, KnowledgePageSummary } from '@/types';

/** Options can't be styled, so nesting is shown with em spaces. */
export function indent(depth: number): string {
    return '\u2003'.repeat(depth);
}

/**
 * Move a page to another category of this subject or to another subject.
 */
export function MovePageDialog({
    page,
    destinations,
    onOpenChange,
}: {
    page: KnowledgePageSummary;
    destinations: KnowledgeDestination[];
    onOpenChange: (open: boolean) => void;
}) {
    const [subjectId, setSubjectId] = useState(page.subject_id);
    const [categoryId, setCategoryId] = useState<number | null>(
        page.category_id,
    );
    const [processing, setProcessing] = useState(false);
    const categories = flattenCategories(
        destinations.find((destination) => destination.id === subjectId)
            ?.categories ?? [],
    );
    const unchanged =
        subjectId === page.subject_id && categoryId === page.category_id;

    function submit() {
        router.put(
            knowledge.pages.move.url(page.id),
            { subject_id: subjectId, category_id: categoryId },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onSuccess: () => onOpenChange(false),
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <Dialog open onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogTitle>Mover “{page.title}”</DialogTitle>
                <DialogDescription>
                    A página vai para o fim da categoria escolhida, com as
                    imagens e PDFs dela.
                </DialogDescription>
                <div className="grid gap-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="move-subject">Matéria</Label>
                        <NativeSelect
                            id="move-subject"
                            className="h-11 text-base"
                            value={subjectId}
                            onChange={(event) => {
                                setSubjectId(Number(event.target.value));
                                setCategoryId(null);
                            }}
                        >
                            {destinations.map((destination) => (
                                <option
                                    key={destination.id}
                                    value={destination.id}
                                >
                                    {destination.title}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="move-category">Categoria</Label>
                        <NativeSelect
                            id="move-category"
                            className="h-11 text-base"
                            value={categoryId ?? ''}
                            onChange={(event) =>
                                setCategoryId(
                                    event.target.value === ''
                                        ? null
                                        : Number(event.target.value),
                                )
                            }
                        >
                            <option value="">Sem categoria</option>
                            {categories.map(({ category, depth }) => (
                                <option key={category.id} value={category.id}>
                                    {indent(depth)}
                                    {category.title}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                </div>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary" className="h-11">
                            Cancelar
                        </Button>
                    </DialogClose>
                    <Button
                        className="h-11"
                        disabled={processing || unchanged}
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
