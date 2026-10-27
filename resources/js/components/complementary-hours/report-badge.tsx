import { FileText, FileWarning } from 'lucide-react';
import { Badge } from '@/components/ui/badge';

/** Whether an activity already has its report written. */
export function ReportBadge({ hasReport }: { hasReport: boolean }) {
    return hasReport ? (
        <Badge variant="secondary">
            <FileText aria-hidden />
            Com relatório
        </Badge>
    ) : (
        <Badge
            variant="outline"
            className="border-amber-500/50 text-amber-700 dark:text-amber-400"
        >
            <FileWarning aria-hidden />
            Sem relatório
        </Badge>
    );
}
