import { router } from '@inertiajs/react';
import { Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatBytes } from '@/lib/knowledge';
import knowledge from '@/routes/knowledge';
import type { UploadLimits } from './editor/knowledge-editor';
import {
    FILE_ACCEPT,
    IMAGE_ACCEPT,
    unsupportedMessage,
    uploadKind,
} from './editor/uploads';

/**
 * Sends a file (PDF, PowerPoint, Word, Excel or image) as a new page of the
 * subject, named after the file, and opens it in the reader.
 */
export function UploadFileButton({
    subjectId,
    categoryId = null,
    limits,
    variant = 'outline',
}: {
    subjectId: number;
    categoryId?: number | null;
    limits: UploadLimits;
    variant?: 'default' | 'outline';
}) {
    const input = useRef<HTMLInputElement>(null);
    const [progress, setProgress] = useState<number | null>(null);

    function send(file: File) {
        const kind = uploadKind(file);

        if (kind === null) {
            toast.error(unsupportedMessage(file));

            return;
        }

        const max =
            (kind === 'image' ? limits.image_kb : limits.file_kb) * 1024;

        if (file.size > max) {
            toast.error(
                `"${file.name}" tem ${formatBytes(file.size)}; o limite é ${formatBytes(max)}.`,
            );

            return;
        }

        setProgress(0);
        router.post(
            knowledge.pages.upload.url(subjectId),
            { file, category_id: categoryId },
            {
                forceFormData: true,
                onProgress: (event) => setProgress(event?.percentage ?? 0),
                onError: (errors) =>
                    toast.error(
                        errors.file ??
                            errors.category_id ??
                            'Não foi possível enviar o arquivo.',
                    ),
                onFinish: () => setProgress(null),
            },
        );
    }

    return (
        <>
            <Button
                variant={variant}
                className="h-11"
                disabled={progress !== null}
                onClick={() => input.current?.click()}
                title="PDF, PowerPoint, Word, Excel ou imagem"
            >
                {progress === null ? <Upload /> : <Spinner />}
                {progress === null
                    ? 'Enviar arquivo'
                    : `Enviando… ${Math.round(progress)}%`}
            </Button>
            <input
                ref={input}
                type="file"
                accept={`${FILE_ACCEPT},${IMAGE_ACCEPT}`}
                className="sr-only"
                tabIndex={-1}
                onChange={(event) => {
                    const file = event.target.files?.[0];
                    event.target.value = '';

                    if (file) {
                        send(file);
                    }
                }}
            />
        </>
    );
}
