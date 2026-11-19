import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import type { FlashToast } from '@/types/ui';

export function useFlashToast(): void {
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const data = flash?.toast as FlashToast | undefined;

            if (!data) {
                return;
            }

            // A result with details stays longer, so it can be read.
            toast[data.type](
                data.message,
                data.description
                    ? { description: data.description, duration: 10_000 }
                    : undefined,
            );
        });
    }, []);
}
