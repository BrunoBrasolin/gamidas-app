import { Link, router } from '@inertiajs/react';
import { ListChecks, Settings2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';
import shows from '@/routes/shows';
import type { ShowChecklistItem } from '@/types';

/**
 * What to take to the show, from the household's checklist. Checking an item
 * shows it right away and is saved in the background; a failed save reverts it.
 */
export function ShowChecklist({
    showId,
    items,
}: {
    showId: number;
    items: ShowChecklistItem[];
}) {
    const [checked, setChecked] = useState(
        () =>
            new Set(
                items.filter((item) => item.checked).map((item) => item.id),
            ),
    );
    const done = items.filter((item) => checked.has(item.id)).length;

    function toggle(id: number, value: boolean) {
        const apply = (on: boolean) =>
            setChecked((current) => {
                const next = new Set(current);

                if (on) {
                    next.add(id);
                } else {
                    next.delete(id);
                }

                return next;
            });

        apply(value);
        router.put(
            shows.checklist.check.url({ show: showId, item: id }),
            { checked: value },
            {
                only: ['checklist'],
                preserveScroll: true,
                preserveState: true,
                onError: () => apply(!value),
                onCancel: () => apply(!value),
            },
        );
    }

    return (
        <Card>
            <CardHeader className="flex flex-row items-start justify-between gap-3">
                <div className="grid gap-1.5">
                    <CardTitle className="flex items-center gap-2">
                        <ListChecks className="size-4" aria-hidden />
                        Checklist
                    </CardTitle>
                    <CardDescription>
                        {items.length === 0
                            ? 'O que levar para o show'
                            : `${done} de ${items.length} ${items.length === 1 ? 'item separado' : 'itens separados'}`}
                    </CardDescription>
                </div>
                <Button variant="ghost" size="sm" asChild>
                    <Link href={shows.checklist.edit()}>
                        <Settings2 />
                        Configurar
                    </Link>
                </Button>
            </CardHeader>
            <CardContent>
                {items.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        O checklist está vazio. Configure a lista do que levar
                        para todos os shows.
                    </p>
                ) : (
                    <div className="grid gap-4">
                        <div
                            className="h-1.5 overflow-hidden rounded-full bg-muted"
                            role="progressbar"
                            aria-label="Itens separados"
                            aria-valuemin={0}
                            aria-valuemax={items.length}
                            aria-valuenow={done}
                        >
                            <div
                                className="h-full rounded-full bg-primary transition-[width]"
                                style={{
                                    width: `${(done / items.length) * 100}%`,
                                }}
                            />
                        </div>
                        <ul className="grid gap-1 sm:grid-cols-2">
                            {items.map((item) => {
                                const on = checked.has(item.id);
                                const id = `checklist-${item.id}`;

                                return (
                                    <li key={item.id}>
                                        <label
                                            htmlFor={id}
                                            className="flex cursor-pointer items-center gap-3 rounded-md px-2 py-1.5 text-sm hover:bg-muted/60"
                                        >
                                            <Checkbox
                                                id={id}
                                                checked={on}
                                                onCheckedChange={(value) =>
                                                    toggle(
                                                        item.id,
                                                        value === true,
                                                    )
                                                }
                                            />
                                            <span
                                                className={cn(
                                                    on &&
                                                        'text-muted-foreground line-through',
                                                )}
                                            >
                                                {item.label}
                                            </span>
                                        </label>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
