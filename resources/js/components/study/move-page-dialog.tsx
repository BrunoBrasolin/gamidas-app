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
import study from '@/routes/study';
import type { StudyDestination, StudyPageSummary } from '@/types';

/**
 * Move a page to another chapter of this subject or to another subject.
 */
export function MovePageDialog({
    page,
    destinations,
    onOpenChange,
}: {
    page: StudyPageSummary;
    destinations: StudyDestination[];
    onOpenChange: (open: boolean) => void;
}) {
    const [subjectId, setSubjectId] = useState(page.subject_id);
    const [chapterId, setChapterId] = useState<number | null>(page.chapter_id);
    const [processing, setProcessing] = useState(false);
    const chapters =
        destinations.find((destination) => destination.id === subjectId)
            ?.chapters ?? [];
    const unchanged =
        subjectId === page.subject_id && chapterId === page.chapter_id;

    function submit() {
        router.put(
            study.pages.move.url(page.id),
            { subject_id: subjectId, chapter_id: chapterId },
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
                    A página vai para o fim do capítulo escolhido, com as
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
                                setChapterId(null);
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
                        <Label htmlFor="move-chapter">Capítulo</Label>
                        <NativeSelect
                            id="move-chapter"
                            className="h-11 text-base"
                            value={chapterId ?? ''}
                            onChange={(event) =>
                                setChapterId(
                                    event.target.value === ''
                                        ? null
                                        : Number(event.target.value),
                                )
                            }
                        >
                            <option value="">Sem capítulo</option>
                            {chapters.map((chapter) => (
                                <option key={chapter.id} value={chapter.id}>
                                    {chapter.title}
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
