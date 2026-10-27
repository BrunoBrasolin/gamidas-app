import type { KnowledgeAttachment } from '@/types';

/** Images go between paragraphs; anything else is a file card. */
export type UploadKind = 'image' | 'file';

export type UploadState = {
    id: string;
    file: File;
    kind: UploadKind;
    /** 0–100 */
    progress: number;
    status: 'uploading' | 'error';
    error?: string;
};

const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
const FILE_TYPES = [
    'application/pdf',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];
const FILE_EXTENSIONS = ['.pdf', '.pptx', '.docx', '.xlsx'];

export const IMAGE_ACCEPT = IMAGE_TYPES.join(',');
export const FILE_ACCEPT = [...FILE_TYPES, ...FILE_EXTENSIONS].join(',');

/**
 * The kind of a picked file, or null when it is not accepted. Only a first
 * check for a quick message: the server inspects the real contents.
 */
export function uploadKind(file: File): UploadKind | null {
    if (IMAGE_TYPES.includes(file.type)) {
        return 'image';
    }

    const name = file.name.toLowerCase();

    if (
        FILE_TYPES.includes(file.type) ||
        FILE_EXTENSIONS.some((extension) => name.endsWith(extension))
    ) {
        return 'file';
    }

    return null;
}

/** The message shown for a file that is not accepted. */
export function unsupportedMessage(file: File): string {
    return `"${file.name}" não é uma imagem (JPEG, PNG, WebP, GIF), um PDF nem um arquivo do PowerPoint, Word ou Excel (.pptx, .docx, .xlsx).`;
}

/**
 * Uploads in progress (or failed) in the editor, keyed by the id of their
 * placeholder block. A tiny external store for useSyncExternalStore.
 */
export class UploadStore {
    private uploads = new Map<string, UploadState>();
    private listeners = new Set<() => void>();

    subscribe = (listener: () => void): (() => void) => {
        this.listeners.add(listener);

        return () => this.listeners.delete(listener);
    };

    get(id: string): UploadState | undefined {
        return this.uploads.get(id);
    }

    /** How many are still being sent. */
    pending(): number {
        let count = 0;

        this.uploads.forEach((upload) => {
            if (upload.status === 'uploading') {
                count++;
            }
        });

        return count;
    }

    set(id: string, state: UploadState): void {
        this.uploads.set(id, state);
        this.emit();
    }

    update(id: string, patch: Partial<UploadState>): void {
        const current = this.uploads.get(id);

        if (current) {
            this.uploads.set(id, { ...current, ...patch });
            this.emit();
        }
    }

    delete(id: string): void {
        this.uploads.delete(id);
        this.emit();
    }

    private emit(): void {
        this.listeners.forEach((listener) => listener());
    }
}

function xsrfToken(): string | null {
    const match = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.split('=')[1]) : null;
}

function errorMessage(status: number, body: unknown): string {
    const errors = (body as { errors?: Record<string, string[]> } | null)
        ?.errors;
    const first = errors ? Object.values(errors)[0]?.[0] : undefined;

    if (first) {
        return first;
    }

    if (status === 413) {
        return 'O arquivo é maior que o permitido pelo servidor.';
    }

    if (status === 419 || status === 401) {
        return 'Sua sessão expirou. Recarregue a página e tente de novo.';
    }

    if (status === 429) {
        return 'Muitos envios seguidos. Aguarde um pouco e tente de novo.';
    }

    return status === 0
        ? 'Sem conexão. Verifique a internet e tente de novo.'
        : 'Não foi possível enviar o arquivo.';
}

/**
 * Send a file with XMLHttpRequest, which (unlike fetch) reports upload
 * progress. Resolves with the stored attachment; rejects with an Error whose
 * message is ready to show.
 */
export function sendFile(
    url: string,
    file: File,
    onProgress: (percent: number) => void,
): Promise<KnowledgeAttachment> {
    return new Promise((resolve, reject) => {
        const request = new XMLHttpRequest();
        const body = new FormData();
        body.append('file', file);

        request.open('POST', url);
        request.responseType = 'json';
        request.withCredentials = true;
        request.setRequestHeader('Accept', 'application/json');
        request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        const token = xsrfToken();

        if (token) {
            request.setRequestHeader('X-XSRF-TOKEN', token);
        }

        request.upload.onprogress = (event) => {
            if (event.lengthComputable) {
                onProgress(Math.round((event.loaded / event.total) * 100));
            }
        };

        request.onload = () => {
            if (request.status === 201) {
                resolve(request.response as KnowledgeAttachment);
            } else {
                reject(
                    new Error(errorMessage(request.status, request.response)),
                );
            }
        };

        request.onerror = () => reject(new Error(errorMessage(0, null)));

        request.send(body);
    });
}
