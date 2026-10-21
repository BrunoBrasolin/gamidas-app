import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

export function Pagination<T>({ page }: { page: Paginated<T> }) {
    if (page.last_page <= 1) {
        return null;
    }

    return (
        <nav
            aria-label="Paginação"
            className="flex flex-wrap items-center justify-between gap-2 text-sm"
        >
            <span className="text-muted-foreground">
                {page.from}–{page.to} de {page.total}
            </span>
            <div className="flex flex-wrap gap-1">
                {page.links.map((link, index) => {
                    const label = link.label
                        .replace('&laquo; Anterior', '‹ Anterior')
                        .replace('&laquo; Previous', '‹ Anterior')
                        .replace('Próxima &raquo;', 'Próxima ›')
                        .replace('Next &raquo;', 'Próxima ›')
                        .replace('&laquo;', '‹')
                        .replace('&raquo;', '›');

                    return link.url ? (
                        <Link
                            key={index}
                            href={link.url}
                            preserveScroll
                            className={cn(
                                'rounded-md border px-3 py-1',
                                link.active
                                    ? 'bg-primary text-primary-foreground'
                                    : 'hover:bg-muted',
                            )}
                        >
                            {label}
                        </Link>
                    ) : (
                        <span
                            key={index}
                            className="rounded-md border px-3 py-1 text-muted-foreground opacity-50"
                        >
                            {label}
                        </span>
                    );
                })}
            </div>
        </nav>
    );
}
