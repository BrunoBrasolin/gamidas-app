import { useCallback, useEffect, useRef, useState } from 'react';
import { HttpError, jsonRequest } from '@/lib/http';

export type SaveState = 'saved' | 'pending' | 'saving' | 'error' | 'conflict';

type Payload = { title: string; content: unknown };

type SaveResponse = { revision: number; updated_at: string | null };

const DEBOUNCE_MS = 1200;
const RETRY_MS = [3000, 10000, 30000];

/**
 * Autosave for the study editor.
 *
 * - Changes are debounced, and only one save is in flight at a time; edits
 *   made while saving are sent right after.
 * - Every save carries the revision it was based on. When the page was saved
 *   elsewhere the server answers 409 and nothing is overwritten until the
 *   user picks a version (state "conflict").
 * - Network/server failures retry with backoff while keeping the text in the
 *   editor; validation errors (422) wait for the next edit.
 */
export function useAutosave({
    url,
    initialRevision,
    initialSavedAt,
    getPayload,
}: {
    url: string;
    initialRevision: number;
    initialSavedAt: string | null;
    getPayload: () => Payload;
}) {
    const [state, setState] = useState<SaveState>('saved');
    const [error, setError] = useState<string | null>(null);
    const [savedAt, setSavedAt] = useState<string | null>(initialSavedAt);

    const revision = useRef(initialRevision);
    const version = useRef(0);
    const savedVersion = useRef(0);
    const inFlight = useRef<Promise<boolean> | null>(null);
    const timer = useRef<number | undefined>(undefined);
    const attempts = useRef(0);
    const conflicted = useRef(false);
    const payload = useRef(getPayload);

    useEffect(() => {
        payload.current = getPayload;
    }, [getPayload]);

    const save = useCallback(
        async (force = false): Promise<boolean> => {
            window.clearTimeout(timer.current);

            if (inFlight.current) {
                await inFlight.current;
            }

            if (conflicted.current && !force) {
                return false;
            }

            if (!force && version.current === savedVersion.current) {
                return true;
            }

            const target = version.current;
            setState('saving');

            const run = (async () => {
                try {
                    const response = await jsonRequest<SaveResponse>(
                        'PUT',
                        url,
                        {
                            ...payload.current(),
                            revision: revision.current,
                            force,
                        },
                    );

                    revision.current = response.revision;
                    savedVersion.current = target;
                    attempts.current = 0;
                    conflicted.current = false;
                    setSavedAt(response.updated_at);
                    setError(null);

                    if (version.current !== target) {
                        setState('pending');
                        timer.current = window.setTimeout(
                            () => void save(),
                            DEBOUNCE_MS,
                        );
                    } else {
                        setState('saved');
                    }

                    return true;
                } catch (exception) {
                    if (
                        exception instanceof HttpError &&
                        exception.status === 409
                    ) {
                        conflicted.current = true;
                        setState('conflict');

                        return false;
                    }

                    setState('error');

                    if (
                        exception instanceof HttpError &&
                        exception.status === 422
                    ) {
                        const body = exception.body as {
                            message?: string;
                            errors?: Record<string, string[]>;
                        } | null;
                        setError(
                            Object.values(body?.errors ?? {})[0]?.[0] ??
                                body?.message ??
                                'Não foi possível salvar.',
                        );

                        return false;
                    }

                    setError(
                        exception instanceof HttpError &&
                            (exception.status === 419 ||
                                exception.status === 401)
                            ? 'Sua sessão expirou. Copie o texto e recarregue a página.'
                            : 'Sem conexão com o servidor. Vamos tentar de novo.',
                    );

                    const delay =
                        RETRY_MS[Math.min(attempts.current, RETRY_MS.length - 1)];
                    attempts.current++;
                    timer.current = window.setTimeout(() => void save(), delay);

                    return false;
                } finally {
                    inFlight.current = null;
                }
            })();

            inFlight.current = run;

            return run;
        },
        [url],
    );

    /** Call on every edit. */
    const markChanged = useCallback(() => {
        version.current++;

        if (conflicted.current) {
            return;
        }

        setState((current) => (current === 'saving' ? current : 'pending'));
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(() => void save(), DEBOUNCE_MS);
    }, [save]);

    /** Save now (Cmd+S, leaving the page, app going to background). */
    const flush = useCallback(() => save(), [save]);

    /** Keep this editor's version, overwriting the newer one. */
    const keepMine = useCallback(() => save(true), [save]);

    const isDirty = useCallback(
        () =>
            version.current !== savedVersion.current ||
            inFlight.current !== null,
        [],
    );

    useEffect(() => () => window.clearTimeout(timer.current), []);

    return { state, error, savedAt, markChanged, flush, keepMine, isDirty };
}
