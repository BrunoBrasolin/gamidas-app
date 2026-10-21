import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { capitalize, formatMonth, shiftMonth } from '@/lib/format';

export function MonthNavigator({
    month,
    onChange,
}: {
    month: string;
    onChange: (month: string) => void;
}) {
    return (
        <div className="flex items-center gap-1">
            <Button
                type="button"
                variant="outline"
                size="icon"
                aria-label="Mês anterior"
                onClick={() => onChange(shiftMonth(month, -1))}
            >
                <ChevronLeft />
            </Button>
            <label className="relative">
                <span className="sr-only">Selecionar mês</span>
                <span className="pointer-events-none flex h-9 min-w-44 items-center justify-center rounded-md border px-3 text-sm font-medium">
                    {capitalize(formatMonth(month))}
                </span>
                <input
                    type="month"
                    value={month}
                    min="2000-01"
                    max="2100-12"
                    onChange={(event) =>
                        event.target.value && onChange(event.target.value)
                    }
                    className="absolute inset-0 cursor-pointer opacity-0"
                />
            </label>
            <Button
                type="button"
                variant="outline"
                size="icon"
                aria-label="Próximo mês"
                onClick={() => onChange(shiftMonth(month, 1))}
            >
                <ChevronRight />
            </Button>
        </div>
    );
}

export function YearNavigator({
    year,
    onChange,
}: {
    year: number;
    onChange: (year: number) => void;
}) {
    return (
        <div className="flex items-center gap-1">
            <Button
                type="button"
                variant="outline"
                size="icon"
                aria-label="Ano anterior"
                onClick={() => onChange(year - 1)}
            >
                <ChevronLeft />
            </Button>
            <span className="flex h-9 min-w-20 items-center justify-center rounded-md border px-3 text-sm font-medium tabular-nums">
                {year}
            </span>
            <Button
                type="button"
                variant="outline"
                size="icon"
                aria-label="Próximo ano"
                onClick={() => onChange(year + 1)}
            >
                <ChevronRight />
            </Button>
        </div>
    );
}
