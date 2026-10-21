import { router } from '@inertiajs/react';

type FilterValue = string | number | null | undefined;

/**
 * Navigates to the same page with updated query-string filters, keeping the
 * scroll position and dropping empty values and the current page number.
 */
export function useQueryFilters<T extends Record<string, FilterValue>>(
    url: string,
    current: T,
) {
    return (changes: Partial<T>) => {
        const query: Record<string, string | number> = {};

        Object.entries({ ...current, ...changes }).forEach(([key, value]) => {
            if (value !== null && value !== undefined && value !== '') {
                query[key] = value;
            }
        });

        router.get(url, query, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };
}
