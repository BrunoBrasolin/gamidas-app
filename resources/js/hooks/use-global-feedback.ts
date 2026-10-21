import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';

/**
 * Error keys raised by domain rules rather than by a form field (for example
 * "this expense is already paid"). They are shown as toasts because the form
 * that triggered them may not display them.
 */
const GLOBAL_ERROR_KEYS = [
    'expense',
    'payment',
    'recurring_expense',
    'salaries',
];

const HTTP_MESSAGES: Record<number, string> = {
    403: 'Você não tem permissão para realizar esta ação.',
    404: 'Registro não encontrado ou sem acesso.',
    419: 'Sua sessão expirou. Recarregue a página e tente novamente.',
    429: 'Muitas tentativas em pouco tempo. Aguarde um instante.',
};

export function useGlobalFeedback(): void {
    useEffect(() => {
        const offError = router.on('error', (event) => {
            const errors = event.detail.errors;

            GLOBAL_ERROR_KEYS.forEach((key) => {
                if (errors[key]) {
                    toast.error(errors[key]);
                }
            });
        });

        const offException = router.on('httpException', (event) => {
            const message = HTTP_MESSAGES[event.detail.response.status];

            if (message) {
                event.preventDefault();
                toast.error(message);
            }
        });

        return () => {
            offError();
            offException();
        };
    }, []);
}
