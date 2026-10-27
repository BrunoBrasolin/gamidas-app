import { MoreHorizontal, Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/finance/confirm-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatRelative, plural } from '@/lib/knowledge';
import knowledge from '@/routes/knowledge';
import type { KnowledgeSubjectFile } from '@/types';
import { FileCard } from './attachment-viewers';
import { PromptDialog } from './prompt-dialog';

type Dialog =
    | { type: 'rename'; file: KnowledgeSubjectFile }
    | { type: 'delete'; file: KnowledgeSubjectFile }
    | null;

/** "Aula 3.pptx" → "Aula 3" (the extension is kept by the server). */
function baseName(name: string): string {
    return name.replace(/\.[A-Za-z0-9]{1,5}$/, '');
}

/**
 * The files attached to a subject itself (lesson slides, handouts), each
 * opening in the built-in reader.
 */
export function SubjectFiles({ files }: { files: KnowledgeSubjectFile[] }) {
    const [dialog, setDialog] = useState<Dialog>(null);

    if (files.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby="files-title" className="space-y-3">
            <div className="flex items-baseline gap-2">
                <h2
                    id="files-title"
                    className="font-serif text-xl font-semibold"
                >
                    Anexos
                </h2>
                <span className="text-sm text-ink-soft">
                    {plural(files.length, 'arquivo', 'arquivos')}
                </span>
            </div>
            <ul className="grid gap-2 md:grid-cols-2">
                {files.map((file) => (
                    <li key={file.id} className="min-w-0">
                        <FileCard
                            attachment={file}
                            className="h-full"
                            details={`Adicionado ${formatRelative(file.added_at)}`}
                            actions={
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="size-10"
                                            aria-label={`Ações de ${file.name}`}
                                        >
                                            <MoreHorizontal />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem
                                            className="min-h-10"
                                            onSelect={() =>
                                                setDialog({
                                                    type: 'rename',
                                                    file,
                                                })
                                            }
                                        >
                                            <Pencil />
                                            Renomear
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            className="min-h-10"
                                            variant="destructive"
                                            onSelect={() =>
                                                setDialog({
                                                    type: 'delete',
                                                    file,
                                                })
                                            }
                                        >
                                            <Trash2 />
                                            Excluir
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            }
                        />
                    </li>
                ))}
            </ul>
            {dialog?.type === 'rename' && (
                <PromptDialog
                    open
                    onOpenChange={(open) => !open && setDialog(null)}
                    title="Renomear arquivo"
                    label="Nome"
                    initialValue={baseName(dialog.file.name)}
                    method="put"
                    url={knowledge.attachments.update.url(dialog.file.id)}
                    maxLength={190}
                    onSuccess={() => setDialog(null)}
                />
            )}
            {dialog?.type === 'delete' && (
                <ConfirmDialog
                    open
                    onOpenChange={(open) => !open && setDialog(null)}
                    title="Excluir arquivo?"
                    description={`“${dialog.file.name}” será excluído da matéria. Não dá para desfazer.`}
                    confirmLabel="Excluir"
                    url={knowledge.attachments.destroy.url(dialog.file.id)}
                    onSuccess={() => setDialog(null)}
                />
            )}
        </section>
    );
}
