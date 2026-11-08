import { FileCheck2, FileWarning } from 'lucide-react';
import { Badge } from '@/components/ui/badge';

/** Whether the college's report form of an activity was filled in. */
export function ReportBadge({ reportDone }: { reportDone: boolean }) {
    return reportDone ? (
        <Badge variant="secondary">
            <FileCheck2 aria-hidden />
            Relatório preenchido
        </Badge>
    ) : (
        <Badge
            variant="outline"
            className="border-amber-500/50 text-amber-700 dark:text-amber-400"
        >
            <FileWarning aria-hidden />
            Relatório pendente
        </Badge>
    );
}
