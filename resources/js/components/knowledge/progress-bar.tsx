import { plural } from '@/lib/knowledge';
import type { KnowledgeSubjectCounts } from '@/types';

/**
 * Reviewed and in-progress pages of a subject as a thin two-tone bar.
 */
export function ProgressBar({
    counts,
    showLabel = false,
}: {
    counts: KnowledgeSubjectCounts;
    showLabel?: boolean;
}) {
    const total = counts.pages;
    const reviewed = total === 0 ? 0 : (counts.reviewed / total) * 100;
    const progress = total === 0 ? 0 : (counts.in_progress / total) * 100;
    const label =
        total === 0
            ? 'Nenhuma página ainda'
            : `${plural(counts.reviewed, 'revisada', 'revisadas')} · ${plural(counts.in_progress, 'em estudo', 'em estudo')} de ${plural(total, 'página', 'páginas')}`;

    return (
        <div className="space-y-1.5">
            <div
                className="flex h-1.5 overflow-hidden rounded-full bg-paper-edge"
                role="img"
                aria-label={label}
            >
                <div
                    className="bg-emerald-600 dark:bg-emerald-500"
                    style={{ width: `${reviewed}%` }}
                />
                <div
                    className="bg-amber-500/80"
                    style={{ width: `${progress}%` }}
                />
            </div>
            {showLabel && <p className="text-xs text-ink-soft">{label}</p>}
        </div>
    );
}
