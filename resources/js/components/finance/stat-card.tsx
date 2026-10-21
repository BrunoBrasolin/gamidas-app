import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

export function StatCard({
    title,
    value,
    hint,
    icon: Icon,
    className,
}: {
    title: string;
    value: ReactNode;
    hint?: ReactNode;
    icon?: LucideIcon;
    className?: string;
}) {
    return (
        <Card className={cn('gap-2 py-4', className)}>
            <CardContent className="space-y-1 px-4">
                <div className="flex items-center justify-between gap-2 text-sm text-muted-foreground">
                    <span>{title}</span>
                    {Icon && <Icon className="size-4" aria-hidden />}
                </div>
                <div className="text-2xl font-semibold tracking-tight">
                    {value}
                </div>
                {hint && (
                    <div className="text-xs text-muted-foreground">{hint}</div>
                )}
            </CardContent>
        </Card>
    );
}
