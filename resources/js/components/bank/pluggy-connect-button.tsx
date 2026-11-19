import { router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { HttpError, jsonRequest } from '@/lib/http';
import bank from '@/routes/bank';

type PluggyConnectOptions = {
    connectToken: string;
    updateItem?: string;
    onSuccess: (data: { item: { id: string } }) => void;
    onError?: (error: { message?: string }) => void;
};

declare global {
    interface Window {
        PluggyConnect?: new (options: PluggyConnectOptions) => {
            init: () => void;
        };
    }
}

let scriptPromise: Promise<void> | null = null;

/**
 * Pluggy's widget script, loaded from its CDN only when a member opens it.
 */
function loadScript(src: string): Promise<void> {
    if (window.PluggyConnect) {
        return Promise.resolve();
    }

    scriptPromise ??= new Promise<void>((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => {
            scriptPromise = null;
            reject(new Error('script'));
        };
        document.head.appendChild(script);
    });

    return scriptPromise;
}

/**
 * Opens Pluggy Connect to connect a bank (or, with a connection, to renew
 * its authorization). The server issues the short-lived connect token; the
 * new item is then attached to the member and synced.
 */
export function PluggyConnectButton({
    script,
    connectionId,
    children,
    variant = 'default',
    size = 'default',
}: {
    script: string;
    connectionId?: number;
    children: ReactNode;
    variant?: 'default' | 'outline' | 'secondary' | 'ghost';
    size?: 'default' | 'sm';
}) {
    const [loading, setLoading] = useState(false);

    async function open() {
        setLoading(true);

        try {
            const [{ token, item_id: itemId }] = await Promise.all([
                jsonRequest<{ token: string; item_id: string | null }>(
                    'POST',
                    bank.connectToken.url(),
                    connectionId ? { connection: connectionId } : {},
                ),
                loadScript(script),
            ]);

            if (!window.PluggyConnect) {
                throw new Error('script');
            }

            new window.PluggyConnect({
                connectToken: token,
                ...(itemId ? { updateItem: itemId } : {}),
                onSuccess: ({ item }) => {
                    if (connectionId) {
                        router.post(
                            bank.connections.sync.url(connectionId),
                            {},
                            { preserveScroll: true },
                        );
                    } else {
                        router.post(bank.connections.store.url(), {
                            item_id: item.id,
                        });
                    }
                },
                onError: (error) => {
                    toast.error(
                        error?.message ??
                            'A conexão com o banco não foi concluída.',
                    );
                },
            }).init();
        } catch (error) {
            const message =
                error instanceof HttpError &&
                typeof (error.body as { message?: unknown })?.message ===
                    'string'
                    ? (error.body as { message: string }).message
                    : 'Não foi possível abrir o Pluggy Connect. Tente de novo.';

            toast.error(message);
        } finally {
            setLoading(false);
        }
    }

    return (
        <Button
            type="button"
            variant={variant}
            size={size}
            onClick={open}
            disabled={loading}
        >
            {loading && <Spinner />}
            {children}
        </Button>
    );
}
