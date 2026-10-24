import { Link } from '@inertiajs/react';
import { ChevronLeft, LibraryBig } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import study from '@/routes/study';

/**
 * Top bar of the focus layout: back to the subject (or library), a middle
 * slot and actions on the right. Stays at the top while scrolling.
 */
export function StudyTopBar({
    backHref,
    backLabel,
    children,
    actions,
}: {
    backHref: string;
    backLabel: string;
    children?: ReactNode;
    actions?: ReactNode;
}) {
    return (
        <header className="sticky top-0 z-30 border-b border-paper-edge bg-paper/95 pt-[env(safe-area-inset-top)] backdrop-blur supports-[backdrop-filter]:bg-paper/80">
            <div className="mx-auto flex h-14 max-w-6xl items-center gap-1 px-2 sm:px-4">
                <Button
                    variant="ghost"
                    className="h-10 max-w-[45%] shrink-0 gap-1 px-2"
                    asChild
                >
                    <Link href={backHref}>
                        <ChevronLeft className="size-5" />
                        <span className="truncate">{backLabel}</span>
                    </Link>
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    className="hidden size-10 sm:inline-flex"
                    asChild
                >
                    <Link href={study.index()} aria-label="Biblioteca">
                        <LibraryBig />
                    </Link>
                </Button>
                <div className="flex min-w-0 flex-1 items-center justify-center">
                    {children}
                </div>
                <div className="flex shrink-0 items-center gap-1">{actions}</div>
            </div>
        </header>
    );
}
