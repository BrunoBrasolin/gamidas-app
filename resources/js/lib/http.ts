export class HttpError extends Error {
    constructor(
        public readonly status: number,
        public readonly body: unknown,
    ) {
        super(`HTTP ${status}`);
    }
}

function xsrfToken(): string | null {
    const match = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.split('=')[1]) : null;
}

/**
 * A same-origin JSON request carrying Laravel's CSRF token, for endpoints
 * that are not Inertia visits (such as note autosave). Resolves with the
 * parsed body; rejects with HttpError for non-2xx answers and with a
 * TypeError when the network fails.
 */
export async function jsonRequest<T>(
    method: 'GET' | 'POST' | 'PUT',
    url: string,
    body?: unknown,
    signal?: AbortSignal,
): Promise<T> {
    const token = xsrfToken();
    const init: RequestInit = {
        method,
        signal,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { 'X-XSRF-TOKEN': token } : {}),
        },
    };

    if (method !== 'GET' && body !== undefined) {
        init.body = JSON.stringify(body);
    }

    const response = await fetch(url, init);

    const data: unknown = await response.json().catch(() => null);

    if (!response.ok) {
        throw new HttpError(response.status, data);
    }

    return data as T;
}
