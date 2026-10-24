import { router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

/**
 * Asks for confirmation before sending a request, typically a deletion.
 * Opens from its trigger, or is controlled through open/onOpenChange (to
 * open it from a menu item).
 */
export function ConfirmDialog({
    trigger,
    open: controlledOpen,
    onOpenChange,
    title,
    description,
    confirmLabel = 'Confirmar',
    destructive = true,
    method = 'delete',
    url,
    onSuccess,
}: {
    trigger?: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    title: string;
    description: ReactNode;
    confirmLabel?: string;
    destructive?: boolean;
    method?: 'delete' | 'post';
    url: string;
    onSuccess?: () => void;
}) {
    const [uncontrolledOpen, setUncontrolledOpen] = useState(false);
    const open = controlledOpen ?? uncontrolledOpen;

    function setOpen(next: boolean) {
        setUncontrolledOpen(next);
        onOpenChange?.(next);
    }
    const [processing, setProcessing] = useState(false);

    function confirm() {
        router.visit(url, {
            method,
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onSuccess: () => onSuccess?.(),
            onFinish: () => {
                setProcessing(false);
                setOpen(false);
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            {trigger && <DialogTrigger asChild>{trigger}</DialogTrigger>}
            <DialogContent>
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>{description}</DialogDescription>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">Cancelar</Button>
                    </DialogClose>
                    <Button
                        variant={destructive ? 'destructive' : 'default'}
                        disabled={processing}
                        onClick={confirm}
                    >
                        {processing && <Spinner />}
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
