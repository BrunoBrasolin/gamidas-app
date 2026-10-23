import { Head, Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, NotebookText, Tags } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/finance/empty-state';
import { FilterSelect } from '@/components/finance/filter-select';
import { PageHeader } from '@/components/finance/page-header';
import { CellDialog } from '@/components/time/cell-dialog';
import type { CellTarget } from '@/components/time/cell-dialog';
import { Timers } from '@/components/time/timers';
import { Button } from '@/components/ui/button';
import { useQueryFilters } from '@/hooks/use-query-filters';
import { capitalize, formatMonth, formatShortDate, today } from '@/lib/format';
import { formatClock, shortWeekday, weekdayIndex } from '@/lib/time';
import { cn } from '@/lib/utils';
import time from '@/routes/time';
import type { CategoryOption, TimeCell, TimerList } from '@/types';

type Props = {
    filters: {
        view: 'week' | 'month';
        date: string;
        category: number | null;
        sort: 'name' | 'total';
    };
    period: { from: string; to: string; previous: string; next: string };
    sheet: {
        days: { date: string; is_today: boolean; total_seconds: number }[];
        categories: {
            id: number;
            name: string;
            archived: boolean;
            daily_goal_seconds: number | null;
            total_seconds: number;
        }[];
        cells: Record<string, Record<number, TimeCell>>;
        total_seconds: number;
    };
    category_options: CategoryOption[];
    timers: TimerList;
};

function query(filters: Props['filters']) {
    return {
        view: filters.view,
        date: filters.date,
        category: filters.category ?? undefined,
        sort: filters.sort === 'name' ? undefined : filters.sort,
    };
}

export default function TimeSheet({
    filters,
    period,
    sheet,
    category_options,
    timers,
}: Props) {
    const filter = useQueryFilters(time.index.url(), filters);
    const [target, setTarget] = useState<CellTarget | null>(null);
    const title =
        filters.view === 'month'
            ? capitalize(formatMonth(period.from.slice(0, 7)))
            : `Semana de ${formatShortDate(period.from)} a ${formatShortDate(period.to)}`;

    return (
        <>
            <Head title="Planilha de horas" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title={title}
                    description="Horas trabalhadas por dia e categoria. Clique numa célula para lançar o tempo e escrever a nota do dia."
                >
                    <nav
                        className="inline-flex rounded-lg bg-muted p-1 text-sm"
                        aria-label="Visão"
                    >
                        {(
                            [
                                ['week', 'Semana'],
                                ['month', 'Mês'],
                            ] as const
                        ).map(([view, label]) => (
                            <Link
                                key={view}
                                href={time.index({
                                    query: { ...query(filters), view },
                                })}
                                aria-current={
                                    filters.view === view ? 'page' : undefined
                                }
                                className={cn(
                                    'rounded-md px-3 py-1.5 font-medium',
                                    filters.view === view
                                        ? 'bg-background shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {label}
                            </Link>
                        ))}
                    </nav>
                    <div className="flex items-center gap-1">
                        <Button variant="outline" size="icon" asChild>
                            <Link
                                href={time.index({
                                    query: {
                                        ...query(filters),
                                        date: period.previous,
                                    },
                                })}
                                aria-label="Período anterior"
                                preserveScroll
                            >
                                <ChevronLeft />
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link
                                href={time.index({
                                    query: { ...query(filters), date: today() },
                                })}
                            >
                                Hoje
                            </Link>
                        </Button>
                        <Button variant="outline" size="icon" asChild>
                            <Link
                                href={time.index({
                                    query: {
                                        ...query(filters),
                                        date: period.next,
                                    },
                                })}
                                aria-label="Próximo período"
                                preserveScroll
                            >
                                <ChevronRight />
                            </Link>
                        </Button>
                    </div>
                </PageHeader>

                <Timers timers={timers} />

                <div className="flex flex-wrap items-end gap-3">
                    <FilterSelect
                        id="sheet-category"
                        label="Categoria"
                        value={filters.category ?? ''}
                        options={[
                            ['', 'Todas'],
                            ...category_options.map(
                                (category): [string, string] => [
                                    String(category.id),
                                    category.archived
                                        ? `${category.name} (arquivada)`
                                        : category.name,
                                ],
                            ),
                        ]}
                        onChange={(value) =>
                            filter({
                                category: value === '' ? null : Number(value),
                            })
                        }
                    />
                    <FilterSelect
                        id="sheet-sort"
                        label="Ordenar colunas"
                        value={filters.sort}
                        options={[
                            ['name', 'Por nome'],
                            ['total', 'Por tempo no período'],
                        ]}
                        onChange={(value) =>
                            filter({ sort: value as Props['filters']['sort'] })
                        }
                    />
                </div>

                {sheet.categories.length === 0 ? (
                    <EmptyState
                        icon={Tags}
                        title="Nenhuma categoria para mostrar"
                        description="Crie uma categoria para cada trabalho ou projeto (por exemplo, o nome do cliente) e lance as horas de cada dia."
                    >
                        <Button asChild>
                            <Link href={time.categories.index()}>
                                Criar categoria
                            </Link>
                        </Button>
                    </EmptyState>
                ) : (
                    <div className="max-h-[70vh] overflow-auto rounded-xl border">
                        <table className="w-full border-collapse text-sm">
                            <thead className="sticky top-0 z-10 bg-muted">
                                <tr>
                                    <th
                                        scope="col"
                                        className="px-3 py-2 text-left font-medium"
                                    >
                                        Dia
                                    </th>
                                    {sheet.categories.map((category) => (
                                        <th
                                            key={category.id}
                                            scope="col"
                                            className="min-w-36 px-3 py-2 text-right font-medium"
                                        >
                                            {category.name}
                                            {category.archived && (
                                                <span className="ml-1 text-xs font-normal text-muted-foreground">
                                                    (arquivada)
                                                </span>
                                            )}
                                        </th>
                                    ))}
                                    <th
                                        scope="col"
                                        className="px-3 py-2 text-right font-medium"
                                    >
                                        Total
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {sheet.days.map((day) => {
                                    const weekend = [0, 6].includes(
                                        weekdayIndex(day.date),
                                    );

                                    return (
                                        <tr
                                            key={day.date}
                                            className={cn(
                                                'border-t',
                                                weekend && 'bg-muted/40',
                                                day.is_today &&
                                                    'bg-primary/10 font-medium',
                                            )}
                                        >
                                            <th
                                                scope="row"
                                                className="px-3 py-1.5 text-left font-normal whitespace-nowrap"
                                            >
                                                <span className="tabular-nums">
                                                    {formatShortDate(day.date)}
                                                </span>{' '}
                                                <span className="text-xs text-muted-foreground">
                                                    {shortWeekday(day.date)}
                                                </span>
                                            </th>
                                            {sheet.categories.map(
                                                (category) => {
                                                    const cell =
                                                        sheet.cells[day.date]?.[
                                                            category.id
                                                        ];

                                                    const goal =
                                                        category.daily_goal_seconds;
                                                    const tracked =
                                                        cell?.seconds ?? 0;
                                                    // Future days have no verdict yet.
                                                    const verdict =
                                                        goal !== null &&
                                                        day.date <= today()
                                                            ? tracked >= goal
                                                                ? 'met'
                                                                : 'missed'
                                                            : null;

                                                    return (
                                                        <td
                                                            key={category.id}
                                                            className={cn(
                                                                'p-0',
                                                                verdict ===
                                                                    'met' &&
                                                                    'bg-green-500/20 text-green-800 dark:text-green-300',
                                                                verdict ===
                                                                    'missed' &&
                                                                    'bg-red-500/20 text-red-800 dark:text-red-300',
                                                            )}
                                                            title={
                                                                goal !== null
                                                                    ? `Meta: ${formatClock(goal)}`
                                                                    : undefined
                                                            }
                                                        >
                                                            <button
                                                                type="button"
                                                                className="flex w-full items-center justify-end gap-1.5 px-3 py-1.5 tabular-nums hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                                                                aria-label={`${category.name}, ${formatShortDate(day.date)}`}
                                                                onClick={() =>
                                                                    setTarget({
                                                                        categoryId:
                                                                            category.id,
                                                                        categoryName:
                                                                            category.name,
                                                                        date: day.date,
                                                                        cell,
                                                                    })
                                                                }
                                                            >
                                                                {cell?.note && (
                                                                    <NotebookText
                                                                        className="size-3.5 text-muted-foreground"
                                                                        aria-label="Tem nota"
                                                                    />
                                                                )}
                                                                {cell &&
                                                                cell.seconds >
                                                                    0 ? (
                                                                    formatClock(
                                                                        cell.seconds,
                                                                    )
                                                                ) : (
                                                                    <span className="text-muted-foreground/50">
                                                                        –
                                                                    </span>
                                                                )}
                                                            </button>
                                                        </td>
                                                    );
                                                },
                                            )}
                                            <td className="px-3 py-1.5 text-right font-medium tabular-nums">
                                                {day.total_seconds > 0
                                                    ? formatClock(
                                                          day.total_seconds,
                                                      )
                                                    : ''}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                            <tfoot className="sticky bottom-0 bg-muted font-medium">
                                <tr className="border-t">
                                    <th
                                        scope="row"
                                        className="px-3 py-2 text-left"
                                    >
                                        Total
                                    </th>
                                    {sheet.categories.map((category) => (
                                        <td
                                            key={category.id}
                                            className="px-3 py-2 text-right tabular-nums"
                                        >
                                            {formatClock(
                                                category.total_seconds,
                                            )}
                                        </td>
                                    ))}
                                    <td className="px-3 py-2 text-right tabular-nums">
                                        {formatClock(sheet.total_seconds)}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                )}
            </div>

            <CellDialog target={target} onClose={() => setTarget(null)} />
        </>
    );
}

TimeSheet.layout = {
    breadcrumbs: [
        { title: 'Tempo', href: time.index() },
        { title: 'Planilha', href: time.index() },
    ],
};
