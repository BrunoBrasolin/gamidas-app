import { Form, Head, router } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    Pencil,
    Plus,
    Tags,
    Trash2,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/finance/confirm-dialog';
import { EmptyState } from '@/components/finance/empty-state';
import { FormField } from '@/components/finance/form-field';
import { PageHeader } from '@/components/finance/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatClock, formatDuration, parseClock } from '@/lib/time';
import time from '@/routes/time';
import type { TimeCategory } from '@/types';

function CategoryFormDialog({
    category,
    trigger,
}: {
    category?: TimeCategory;
    trigger: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const [goal, setGoal] = useState(
        category?.daily_goal_seconds
            ? formatClock(category.daily_goal_seconds)
            : '',
    );
    const goalSeconds = parseClock(goal);
    const form = category
        ? time.categories.update.form(category.id)
        : time.categories.store.form();

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {category ? 'Editar categoria' : 'Nova categoria'}
                    </DialogTitle>
                    <DialogDescription>
                        Uma categoria é um trabalho ou projeto, como o nome de
                        um cliente. Renomear não altera as horas já lançadas.
                        Com meta diária, a planilha pinta de verde o dia que a
                        atinge e de vermelho o que fica abaixo.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...form}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    resetOnSuccess
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <FormField
                                id="category-name"
                                label="Nome"
                                error={errors.name}
                            >
                                <Input
                                    id="category-name"
                                    name="name"
                                    required
                                    maxLength={80}
                                    autoFocus
                                    defaultValue={category?.name}
                                />
                            </FormField>
                            <FormField
                                id="category-goal"
                                label="Meta diária (opcional)"
                                error={
                                    errors.daily_goal_seconds ??
                                    (goalSeconds === null
                                        ? 'Use um formato como 6:00, 6h ou 1h30.'
                                        : undefined)
                                }
                            >
                                <Input
                                    id="category-goal"
                                    inputMode="text"
                                    placeholder="Ex.: 6:00"
                                    value={goal}
                                    onChange={(event) =>
                                        setGoal(event.target.value)
                                    }
                                />
                                <input
                                    type="hidden"
                                    name="daily_goal_seconds"
                                    value={goalSeconds ? goalSeconds : ''}
                                />
                            </FormField>
                            <DialogFooter>
                                <Button
                                    type="submit"
                                    disabled={
                                        processing || goalSeconds === null
                                    }
                                >
                                    {processing && <Spinner />}
                                    Salvar
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

export default function TimeCategories({
    categories,
}: {
    categories: TimeCategory[];
}) {
    const post = (url: string, method: 'post' | 'delete') =>
        router.visit(url, { method, preserveScroll: true });

    return (
        <>
            <Head title="Categorias de tempo" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Categorias"
                    description="Cada categoria é uma coluna da planilha de horas (um trabalho, cliente ou projeto)."
                >
                    <CategoryFormDialog
                        trigger={
                            <Button>
                                <Plus />
                                Nova categoria
                            </Button>
                        }
                    />
                </PageHeader>

                {categories.length === 0 ? (
                    <EmptyState
                        icon={Tags}
                        title="Nenhuma categoria ainda"
                        description="Crie, por exemplo, uma categoria para cada empresa em que você trabalha."
                    />
                ) : (
                    <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {categories.map((category) => (
                            <li key={category.id}>
                                <Card>
                                    <CardContent className="flex items-center justify-between gap-3">
                                        <div className="min-w-0 space-y-1">
                                            <p className="flex items-center gap-2 font-medium">
                                                <span className="truncate">
                                                    {category.name}
                                                </span>
                                                {category.archived && (
                                                    <Badge variant="secondary">
                                                        Arquivada
                                                    </Badge>
                                                )}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {formatDuration(
                                                    category.month_seconds,
                                                )}{' '}
                                                neste mês
                                            </p>
                                            {category.daily_goal_seconds !==
                                                null && (
                                                <p className="text-sm text-muted-foreground">
                                                    Meta:{' '}
                                                    {formatDuration(
                                                        category.daily_goal_seconds,
                                                    )}{' '}
                                                    por dia
                                                </p>
                                            )}
                                        </div>
                                        <div className="flex shrink-0 gap-1">
                                            <CategoryFormDialog
                                                category={category}
                                                trigger={
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Editar ${category.name}`}
                                                    >
                                                        <Pencil />
                                                    </Button>
                                                }
                                            />
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={
                                                    category.archived
                                                        ? `Restaurar ${category.name}`
                                                        : `Arquivar ${category.name}`
                                                }
                                                onClick={() =>
                                                    post(
                                                        time.categories.archive.url(
                                                            category.id,
                                                        ),
                                                        category.archived
                                                            ? 'delete'
                                                            : 'post',
                                                    )
                                                }
                                            >
                                                {category.archived ? (
                                                    <ArchiveRestore />
                                                ) : (
                                                    <Archive />
                                                )}
                                            </Button>
                                            {!category.has_history && (
                                                <ConfirmDialog
                                                    trigger={
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            aria-label={`Excluir ${category.name}`}
                                                        >
                                                            <Trash2 />
                                                        </Button>
                                                    }
                                                    title="Excluir categoria?"
                                                    description={`“${category.name}” não tem horas nem notas e será removida.`}
                                                    confirmLabel="Excluir"
                                                    url={time.categories.destroy.url(
                                                        category.id,
                                                    )}
                                                />
                                            )}
                                        </div>
                                    </CardContent>
                                </Card>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

TimeCategories.layout = {
    breadcrumbs: [
        { title: 'Tempo', href: time.index() },
        { title: 'Categorias', href: time.categories.index() },
    ],
};
