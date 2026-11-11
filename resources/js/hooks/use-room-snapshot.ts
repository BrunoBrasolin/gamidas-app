import { useEffect, useState } from 'react';
import { jsonRequest } from '@/lib/http';

/** Longest wait between two requests after failures. */
const MAX_BACKOFF_MS = 60_000;

/**
 * Polls a room snapshot (served from the server's cache) for the live ranking
 * and the projector. Every request that reaches the server counts against the
 * hosting plan, so it waits `intervalMs` between requests, pauses while the tab
 * is hidden (catching up as soon as it is visible again), backs off when the
 * server errors or throttles, and — with `stopWhenClosed` — stops for good once
 * the room reports it is closed, since nothing changes after that.
 */
export function useRoomSnapshot<T extends { open: boolean }>(
    url: string,
    {
        intervalMs,
        stopWhenClosed = true,
    }: { intervalMs: number; stopWhenClosed?: boolean },
): T | null {
    const [data, setData] = useState<T | null>(null);

    useEffect(() => {
        const controller = new AbortController();
        let timer: number | undefined;
        let failures = 0;
        let stopped = false;

        const schedule = () => {
            if (stopped) {
                return;
            }

            const wait = Math.min(intervalMs * 2 ** failures, MAX_BACKOFF_MS);
            timer = window.setTimeout(() => void load(), wait);
        };

        const load = async () => {
            window.clearTimeout(timer);

            if (document.hidden) {
                return; // resumes on visibilitychange
            }

            try {
                const next = await jsonRequest<T>(
                    'GET',
                    url,
                    undefined,
                    controller.signal,
                );

                failures = 0;
                setData(next);

                if (stopWhenClosed && !next.open) {
                    stopped = true;

                    return;
                }
            } catch {
                if (controller.signal.aborted) {
                    return;
                }

                failures += 1;
            }

            schedule();
        };

        const onVisibility = () => {
            if (!document.hidden && !stopped) {
                void load();
            }
        };

        void load();
        document.addEventListener('visibilitychange', onVisibility);

        return () => {
            stopped = true;
            controller.abort();
            window.clearTimeout(timer);
            document.removeEventListener('visibilitychange', onVisibility);
        };
    }, [url, intervalMs, stopWhenClosed]);

    return data;
}
