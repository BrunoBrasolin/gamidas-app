import { Head, Link, router } from '@inertiajs/react';
import type { Editor } from '@tiptap/react';
import {
    AlertTriangle,
    BookOpen,
    Check,
    CloudOff,
    Loader2,
    RefreshCw,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    savableContent,
    StudyEditor,
} from '@/components/study/editor/study-editor';
import type { UploadLimits } from '@/components/study/editor/study-editor';
import { useAutosave } from '@/components/study/editor/use-autosave';
import type { SaveState } from '@/components/study/editor/use-autosave';
import { StatusControl } from '@/components/study/status-control';
import { StudyTopBar } from '@/components/study/top-bar';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import study from '@/routes/study';
import type {
    StudyAttachmentMap,
    StudyPageDetail,
    StudySubject,
} from '@/types';

function SaveIndicator({
    state,
    error,
    savedAt,
    onRetry,
}: {
    state: SaveState;
    error: string | null;
    savedAt: string | null;
    onRetry: () => void;
}) {
    const time = savedAt
        ? new Intl.DateTimeFormat('pt-BR', {
              timeZone: 'America/Sao_Paulo',
              timeStyle: 'short',
          }).format(new Date(savedAt))
        : null;

    if (state === 'error') {
        return (
            <button
                type="button"
                onClick={onRetry}
                className="flex min-h-10 items-center gap-1.5 rounded-md px-2 text-sm text-destructive outline-none focus-visible:ring-[3px] focus-visible:ring-ring"
                title={error ?? undefined}
            >
                <CloudOff className="size-4 shrink-0" aria-hidden />
                <span className="hidden sm:inline">Não salvo —</span>
                <span className="underline underline-offset-2">
                    tentar de novo
                </span>
            </button>
        );
    }

    const content: Record<
        Exclude<SaveState, 'error'>,
        { icon: React.ReactNode; label: string }
    > = {
        saved: {
            icon: <Check className="size-4" aria-hidden />,
            label: time ? `Salvo às ${time}` : 'Salvo',
        },
        pending: {
            icon: <span className="size-2 rounded-full bg-amber-500" aria-hidden />,
            label: 'Alterações não salvas',
        },
        saving: {
            icon: <Loader2 className="size-4 animate-spin" aria-hidden />,
            label: 'Salvando…',
        },
        conflict: {
            icon: <AlertTriangle className="size-4 text-amber-600" aria-hidden />,
            label: 'Alterada em outro lugar',
        },
    };

    return (
        <p
            className="flex items-center gap-1.5 truncate text-sm text-ink-soft"
            role="status"
            aria-live="polite"
        >
            {content[state].icon}
            <span className="truncate">{content[state].label}</span>
        </p>
    );
}

export default function StudyEdit({
    subject,
    page,
    attachments,
    limits,
}: {
    subject: StudySubject;
    page: StudyPageDetail & { revision: number };
    attachments: StudyAttachmentMap;
    limits: UploadLimits;
}) {
    const editorRef = useRef<Editor | null>(null);
    const titleRef = useRef<HTMLTextAreaElement>(null);
    const [title, setTitle] = useState(page.title);
    const titleValue = useRef(page.title);
    const leaving = useRef(false);

    const getPayload = useCallback(
        () => ({
            title: titleValue.current,
            content: editorRef.current
                ? savableContent(editorRef.current)
                : page.content,
        }),
        [page.content],
    );

    const autosave = useAutosave({
        url: study.pages.content.url(page.id),
        initialRevision: page.revision,
        initialSavedAt: page.updated_at,
        getPayload,
    });
    const { flush, isDirty, markChanged } = autosave;

    // Save when the app goes to the background (iPad home gesture, tab
    // switch) and warn before closing with unsaved text.
    useEffect(() => {
        const onHide = () => {
            if (document.visibilityState === 'hidden') {
                void flush();
            }
        };
        const onBeforeUnload = (event: BeforeUnloadEvent) => {
            if (!leaving.current && isDirty()) {
                event.preventDefault();
            }
        };

        document.addEventListener('visibilitychange', onHide);
        window.addEventListener('beforeunload', onBeforeUnload);

        return () => {
            document.removeEventListener('visibilitychange', onHide);
            window.removeEventListener('beforeunload', onBeforeUnload);
        };
    }, [flush, isDirty]);

    // Inside the app: save first, then follow the link. If saving fails,
    // ask before leaving.
    useEffect(() => {
        return router.on('before', (event) => {
            const visit = event.detail.visit;

            if (leaving.current || visit.prefetch || !isDirty()) {
                return;
            }

            void flush().then((saved) => {
                if (
                    saved ||
                    window.confirm(
                        'Não foi possível salvar as últimas alterações. Sair mesmo assim e perdê-las?',
                    )
                ) {
                    leaving.current = true;
                    router.visit(visit.url.href, {
                        method: visit.method,
                        data: visit.data,
                    });
                }
            });

            return false;
        });
    }, [flush, isDirty]);

    // Cmd/Ctrl+S saves now.
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if ((event.metaKey || event.ctrlKey) && event.key === 's') {
                event.preventDefault();
                void flush();
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [flush]);

    // Grow the title box with its text.
    useEffect(() => {
        const element = titleRef.current;

        if (element) {
            element.style.height = 'auto';
            element.style.height = `${element.scrollHeight}px`;
        }
    }, [title]);

    function reloadFromServer() {
        leaving.current = true;
        router.visit(study.pages.edit.url(page.id), {
            preserveState: false,
            onFinish: () => {
                leaving.current = false;
            },
        });
    }

    return (
        <>
            <Head title={`Editando: ${title || 'Sem título'}`} />
            <StudyTopBar
                backHref={study.subjects.show.url(subject.id)}
                backLabel={subject.title}
                actions={
                    <>
                        <StatusControl
                            pageId={page.id}
                            status={page.status}
                            compact
                        />
                        <Button className="ml-1 h-10" asChild>
                            <Link href={study.pages.show(page.id)}>
                                <BookOpen />
                                Ler
                            </Link>
                        </Button>
                    </>
                }
            >
                <SaveIndicator
                    state={autosave.state}
                    error={autosave.error}
                    savedAt={autosave.savedAt}
                    onRetry={() => void flush()}
                />
            </StudyTopBar>

            <main className="mx-auto max-w-3xl px-4 pt-6 sm:px-8 sm:pt-10">
                {autosave.state === 'error' && autosave.error && (
                    <p
                        className="mb-4 rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive"
                        role="alert"
                    >
                        {autosave.error} Seu texto continua aqui no editor.
                    </p>
                )}
                {page.chapter && (
                    <p className="mb-2 text-sm font-medium tracking-wide text-ink-soft uppercase">
                        {page.chapter}
                    </p>
                )}
                <label htmlFor="page-title" className="sr-only">
                    Título da página
                </label>
                <textarea
                    id="page-title"
                    ref={titleRef}
                    rows={1}
                    value={title}
                    maxLength={200}
                    placeholder="Título da página"
                    className="mb-6 block w-full resize-none overflow-hidden border-0 bg-transparent p-0 font-serif text-[2.1rem] leading-tight font-semibold tracking-tight text-ink outline-none placeholder:text-ink-soft/50 focus-visible:ring-0"
                    onChange={(event) => {
                        const value = event.target.value.replace(/\n/g, ' ');
                        setTitle(value);
                        titleValue.current = value;
                        markChanged();
                    }}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            editorRef.current?.commands.focus('start');
                        }
                    }}
                />
                <StudyEditor
                    content={page.content}
                    initialAttachments={attachments}
                    uploadUrl={study.attachments.store.url(page.id)}
                    limits={limits}
                    onChange={markChanged}
                    onReady={(editor) => {
                        editorRef.current = editor;
                    }}
                />
            </main>

            <Dialog open={autosave.state === 'conflict'}>
                <DialogContent className="[&>button:last-child]:hidden">
                    <DialogTitle>Esta página mudou em outro lugar</DialogTitle>
                    <DialogDescription>
                        Ela foi salva em outra aba ou aparelho depois que você
                        abriu o editor. Escolha qual versão manter.
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <Button
                            variant="outline"
                            className="h-11"
                            onClick={reloadFromServer}
                        >
                            <RefreshCw />
                            Usar a versão salva
                        </Button>
                        <Button
                            className="h-11"
                            onClick={() => void autosave.keepMine()}
                        >
                            Manter a minha
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
