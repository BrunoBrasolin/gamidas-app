import { router } from '@inertiajs/react';
import { Check, Circle, CircleDashed, CircleDot } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { STATUS_LABELS, STATUS_ORDER } from '@/lib/knowledge';
import { cn } from '@/lib/utils';
import knowledge from '@/routes/knowledge';
import type { KnowledgePageStatus } from '@/types';

const ICONS: Record<KnowledgePageStatus, LucideIcon> = {
    unread: CircleDashed,
    in_progress: CircleDot,
    reviewed: Check,
};

const TONES: Record<KnowledgePageStatus, string> = {
    unread: 'text-ink-soft',
    in_progress: 'text-amber-600 dark:text-amber-400',
    reviewed: 'text-emerald-700 dark:text-emerald-400',
};

export function StatusIcon({
    status,
    className,
}: {
    status: KnowledgePageStatus;
    className?: string;
}) {
    const Icon = ICONS[status] ?? Circle;

    return (
        <Icon
            className={cn('size-4 shrink-0', TONES[status], className)}
            aria-label={STATUS_LABELS[status]}
        />
    );
}

export function setPageStatus(
    pageId: number,
    status: KnowledgePageStatus,
): void {
    router.put(
        knowledge.pages.status.url(pageId),
        { status },
        { preserveScroll: true, preserveState: true },
    );
}

/**
 * A button showing the page status that opens the three choices.
 */
export function StatusControl({
    pageId,
    status,
    compact = false,
}: {
    pageId: number;
    status: KnowledgePageStatus;
    compact?: boolean;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size={compact ? 'icon' : 'default'}
                    className={cn('h-10', compact ? 'size-10' : 'px-3')}
                    aria-label={`Status: ${STATUS_LABELS[status]}. Alterar`}
                >
                    <StatusIcon status={status} />
                    {!compact && (
                        <span className="text-sm">{STATUS_LABELS[status]}</span>
                    )}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-48">
                <DropdownMenuLabel>Status de estudo</DropdownMenuLabel>
                <DropdownMenuRadioGroup
                    value={status}
                    onValueChange={(value) =>
                        setPageStatus(pageId, value as KnowledgePageStatus)
                    }
                >
                    {STATUS_ORDER.map((option) => (
                        <DropdownMenuRadioItem
                            key={option}
                            value={option}
                            className="min-h-10"
                        >
                            <StatusIcon status={option} />
                            {STATUS_LABELS[option]}
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
