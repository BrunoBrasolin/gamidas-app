import {
    AlertTriangle,
    BookOpen,
    CheckCircle2,
    Columns2,
    ListChecks,
    Pencil,
    RotateCcw,
} from 'lucide-react';
import {
    useCallback,
    useDeferredValue,
    useEffect,
    useRef,
    useState,
} from 'react';
import { MarkdownView } from '@/components/time/markdown';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { HttpError, jsonRequest } from '@/lib/http';
import { cn } from '@/lib/utils';
import time from '@/routes/time';
import type { DailyNote } from '@/types';

const MAX_LENGTH = 200_000;
const AUTOSAVE_DELAY = 1200;

export const REVIEW_TEMPLATE = `## Conquistas
O que eu realizei hoje?

## Aprendizados
O que eu aprendi?

## Bloqueios
O que atrapalhou o progresso?

## Amanhã
No que devo focar em seguida?
`;

type Mode = 'edit' | 'split' | 'read';

type Status = 'idle' | 'saving' | 'saved' | 'error' | 'conflict';

function draftKey(date: string): string {
    return `gamidas:note-draft:${date}`;
}

/**
 * Unsaved drafts are kept in the browser until the server confirms the save,
 * so a failed autosave or a closed tab never loses what was typed.
 */
function readDraft(date: string): string | null {
    try {
        return window.localStorage.getItem(draftKey(date));
    } catch {
        return null;
    }
}

function writeDraft(date: string, content: string | null): void {
    try {
        if (content === null) {
            window.localStorage.removeItem(draftKey(date));
        } else {
            window.localStorage.setItem(draftKey(date), content);
        }
    } catch {
        // Storage may be full or blocked; autosave still runs.
    }
}

/**
 * Markdown editor of a daily note with debounced autosave. Each save sends
 * the revision it started from; when the note was changed elsewhere the
 * server answers 409 and the user chooses which version to keep.
 */
export function NoteEditor({
    note,
    className,
}: {
    note: DailyNote;
    className?: string;
}) {
    const date = note.date;
    const [content, setContent] = useState(note.content);
    const [mode, setMode] = useState<Mode>(
        note.content === '' ? 'edit' : 'read',
    );
    const [status, setStatus] = useState<Status>('idle');
    const [savedContent, setSavedContent] = useState(note.content);
    const [conflict, setConflict] = useState<DailyNote | null>(null);
    const [recovered, setRecovered] = useState<string | null>(() => {
        const draft = readDraft(date);

        return draft !== null && draft !== note.content ? draft : null;
    });

    const revision = useRef(note.revision);
    const saved = useRef(note.content);
    const latest = useRef(note.content);
    const inFlight = useRef(false);
    const timer = useRef<number | undefined>(undefined);
    const preview = useDeferredValue(content);

    const save = useCallback(async () => {
        window.clearTimeout(timer.current);

        if (inFlight.current || latest.current === saved.current) {
            return;
        }

        const sent = latest.current;
        inFlight.current = true;
        setStatus('saving');

        try {
            const result = await jsonRequest<DailyNote>(
                'PUT',
                time.notes.update.url(date),
                { content: sent, base_revision: revision.current },
            );

            revision.current = result.revision;
            saved.current = sent;
            setSavedContent(sent);

            if (latest.current === sent) {
                writeDraft(date, null);
                setStatus('saved');
            } else {
                timer.current = window.setTimeout(
                    () => void save(),
                    AUTOSAVE_DELAY,
                );
            }
        } catch (error) {
            if (error instanceof HttpError && error.status === 409) {
                setConflict(error.body as DailyNote);
                setStatus('conflict');
            } else {
                setStatus('error');
            }
        } finally {
            inFlight.current = false;
        }
    }, [date]);

    function change(next: string) {
        setContent(next);
        latest.current = next;
        writeDraft(date, next);

        if (status !== 'conflict') {
            window.clearTimeout(timer.current);
            timer.current = window.setTimeout(
                () => void save(),
                AUTOSAVE_DELAY,
            );
        }
    }

    function keepMine() {
        if (!conflict) {
            return;
        }

        revision.current = conflict.revision;
        saved.current = conflict.content;
        setSavedContent(conflict.content);
        setConflict(null);
        void save();
    }

    function takeSaved() {
        if (!conflict) {
            return;
        }

        revision.current = conflict.revision;
        saved.current = conflict.content;
        setSavedContent(conflict.content);
        latest.current = conflict.content;
        setContent(conflict.content);
        writeDraft(date, null);
        setConflict(null);
        setStatus('saved');
    }

    function insertTemplate() {
        change(
            content.trim() === ''
                ? REVIEW_TEMPLATE
                : `${content.trimEnd()}\n\n${REVIEW_TEMPLATE}`,
        );
        setMode('edit');
    }

    // Warn before leaving with changes the server has not confirmed, and try
    // a last save when the editor goes away (the draft stays if it fails).
    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => {
            if (latest.current !== saved.current) {
                event.preventDefault();
            }
        };

        window.addEventListener('beforeunload', warn);

        return () => {
            window.removeEventListener('beforeunload', warn);
            void save();
        };
    }, [save]);

    const dirty = content !== savedContent;

    return (
        <div className={cn('flex flex-col gap-3', className)}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div
                    className="inline-flex rounded-lg bg-muted p-1 text-sm"
                    role="group"
                    aria-label="Modo da nota"
                >
                    {(
                        [
                            ['edit', 'Editar', Pencil],
                            ['split', 'Lado a lado', Columns2],
                            ['read', 'Leitura', BookOpen],
                        ] as const
                    ).map(([value, label, Icon]) => (
                        <button
                            key={value}
                            type="button"
                            aria-pressed={mode === value}
                            onClick={() => setMode(value)}
                            className={cn(
                                'inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 font-medium',
                                mode === value
                                    ? 'bg-background shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground',
                                value === 'split' && 'max-lg:hidden',
                            )}
                        >
                            <Icon className="size-3.5" aria-hidden />
                            {label}
                        </button>
                    ))}
                </div>
                <div className="flex items-center gap-2">
                    <SaveStatus
                        status={status}
                        dirty={dirty}
                        onRetry={() => void save()}
                    />
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={insertTemplate}
                    >
                        <ListChecks />
                        Modelo de revisão
                    </Button>
                </div>
            </div>

            {recovered !== null && (
                <Alert>
                    <RotateCcw />
                    <AlertDescription className="flex flex-wrap items-center gap-2">
                        <span>
                            Há um rascunho desta nota que não chegou a ser
                            salvo.
                        </span>
                        <Button
                            type="button"
                            size="sm"
                            onClick={() => {
                                change(recovered);
                                setRecovered(null);
                                setMode('edit');
                            }}
                        >
                            Recuperar rascunho
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => {
                                writeDraft(date, null);
                                setRecovered(null);
                            }}
                        >
                            Descartar
                        </Button>
                    </AlertDescription>
                </Alert>
            )}

            {conflict && (
                <Alert variant="destructive">
                    <AlertTriangle />
                    <AlertDescription className="flex flex-wrap items-center gap-2">
                        <span>
                            Esta nota foi alterada em outra aba ou dispositivo.
                            Qual versão você quer manter?
                        </span>
                        <Button type="button" size="sm" onClick={keepMine}>
                            Manter a minha
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={takeSaved}
                        >
                            Usar a versão salva
                        </Button>
                    </AlertDescription>
                </Alert>
            )}

            <div
                className={cn(
                    'grid gap-3',
                    mode === 'split' && 'lg:grid-cols-2',
                )}
            >
                {mode !== 'read' && (
                    <div className="grid gap-1">
                        <label htmlFor={`note-${date}`} className="sr-only">
                            Nota do dia em Markdown
                        </label>
                        <Textarea
                            id={`note-${date}`}
                            value={content}
                            maxLength={MAX_LENGTH}
                            onChange={(event) => change(event.target.value)}
                            onBlur={() => void save()}
                            placeholder="Escreva livremente em Markdown: # títulos, **negrito**, - [ ] tarefas, > citações, tabelas e blocos de código."
                            className="min-h-72 font-mono text-sm"
                        />
                        <span className="text-right text-xs text-muted-foreground tabular-nums">
                            {content.length.toLocaleString('pt-BR')} /{' '}
                            {MAX_LENGTH.toLocaleString('pt-BR')}
                        </span>
                    </div>
                )}
                {mode !== 'edit' && (
                    <div className="min-h-24 rounded-md border p-4">
                        {preview.trim() === '' ? (
                            <p className="text-sm text-muted-foreground">
                                Nada escrito ainda.
                            </p>
                        ) : (
                            <MarkdownView content={preview} />
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}

function SaveStatus({
    status,
    dirty,
    onRetry,
}: {
    status: Status;
    dirty: boolean;
    onRetry: () => void;
}) {
    if (status === 'saving') {
        return (
            <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                <Spinner className="size-3" />
                Salvando…
            </span>
        );
    }

    if (status === 'error') {
        return (
            <span className="inline-flex items-center gap-1.5 text-xs text-destructive">
                Não foi possível salvar (rascunho guardado neste navegador).
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    className="h-7"
                    onClick={onRetry}
                >
                    Tentar de novo
                </Button>
            </span>
        );
    }

    if (status === 'conflict') {
        return (
            <span className="text-xs text-destructive">
                Conflito de versões
            </span>
        );
    }

    if (dirty) {
        return (
            <span className="text-xs text-muted-foreground">
                Alterações não salvas
            </span>
        );
    }

    return status === 'saved' ? (
        <span
            className="inline-flex items-center gap-1 text-xs text-muted-foreground"
            aria-live="polite"
        >
            <CheckCircle2 className="size-3.5" aria-hidden />
            Salvo
        </span>
    ) : null;
}
