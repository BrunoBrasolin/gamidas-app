import { router } from '@inertiajs/react';
import { Paperclip } from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatBytes, plural } from '@/lib/knowledge';
import knowledge from '@/routes/knowledge';
import type { UploadLimits } from './editor/knowledge-editor';
import {
    FILE_ACCEPT,
    IMAGE_ACCEPT,
    sendFile,
    unsupportedMessage,
    uploadKind,
} from './editor/uploads';

/** Why a file can't be sent, or null when it can. */
function rejection(file: File, limits: UploadLimits): string | null {
    const kind = uploadKind(file);

    if (kind === null) {
        return unsupportedMessage(file);
    }

    const max = (kind === 'image' ? limits.image_kb : limits.file_kb) * 1024;

    return file.size > max
        ? `"${file.name}" tem ${formatBytes(file.size)}; o limite é ${formatBytes(max)}.`
        : null;
}

/**
 * Attaches files (PDF, PowerPoint, Word, Excel or images) to the subject
 * itself, one after the other, then refreshes its "Anexos".
 */
export function AddAttachmentButton({
    subjectId,
    limits,
    variant = 'outline',
}: {
    subjectId: number;
    limits: UploadLimits;
    variant?: 'default' | 'outline';
}) {
    const input = useRef<HTMLInputElement>(null);
    const [sending, setSending] = useState<{
        index: number;
        count: number;
        progress: number;
    } | null>(null);

    async function send(files: File[]) {
        const accepted = files.filter((file) => {
            const problem = rejection(file, limits);

            if (problem) {
                toast.error(problem);
            }

            return problem === null;
        });

        let sent = 0;

        for (const [index, file] of accepted.entries()) {
            setSending({ index, count: accepted.length, progress: 0 });

            try {
                await sendFile(
                    knowledge.subjects.files.store.url(subjectId),
                    file,
                    (progress) =>
                        setSending({
                            index,
                            count: accepted.length,
                            progress,
                        }),
                );
                sent++;
            } catch (exception) {
                toast.error(`${file.name}: ${(exception as Error).message}`);
            }
        }

        setSending(null);

        if (sent > 0) {
            toast.success(
                sent === 1
                    ? 'Anexo adicionado.'
                    : `${plural(sent, 'anexo adicionado', 'anexos adicionados')}.`,
            );
            router.reload({ only: ['files', 'subject'] });
        }
    }

    return (
        <>
            <Button
                variant={variant}
                className="h-11"
                disabled={sending !== null}
                onClick={() => input.current?.click()}
                title="PDF, PowerPoint, Word, Excel ou imagem"
            >
                {sending === null ? <Paperclip /> : <Spinner />}
                {sending === null
                    ? 'Adicionar anexo'
                    : `Enviando${sending.count > 1 ? ` ${sending.index + 1} de ${sending.count}` : ''}… ${sending.progress}%`}
            </Button>
            <input
                ref={input}
                type="file"
                multiple
                accept={`${FILE_ACCEPT},${IMAGE_ACCEPT}`}
                className="sr-only"
                tabIndex={-1}
                onChange={(event) => {
                    const files = [...(event.target.files ?? [])];
                    event.target.value = '';

                    if (files.length > 0) {
                        void send(files);
                    }
                }}
            />
        </>
    );
}
