import { Form, Head } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { ConfirmDialog } from '@/components/finance/confirm-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import household from '@/routes/household';

type CategoryRow = {
    id: number;
    name: string;
    occurrences_count: number;
};

export default function Categories({
    categories,
}: {
    categories: CategoryRow[];
}) {
    return (
        <>
            <Head title="Categorias" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Categorias de despesa"
                    description="Usadas para organizar despesas da casa, recorrentes e compras no cartão."
                />

                <Form
                    {...household.categories.store.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="flex items-start gap-2"
                >
                    {({ errors, processing }) => (
                        <div className="flex w-full max-w-md flex-col gap-1.5">
                            <Label htmlFor="new-category">Nova categoria</Label>
                            <div className="flex gap-2">
                                <Input
                                    id="new-category"
                                    name="name"
                                    required
                                    maxLength={60}
                                    placeholder="Ex.: Condomínio"
                                />
                                <Button type="submit" disabled={processing}>
                                    {processing ? <Spinner /> : <Plus />}
                                    Adicionar
                                </Button>
                            </div>
                            <InputError message={errors.name} />
                        </div>
                    )}
                </Form>

                <ul className="divide-y rounded-md border">
                    {categories.map((category) => (
                        <li
                            key={category.id}
                            className="flex items-center justify-between gap-2 px-4 py-2"
                        >
                            <div>
                                <div className="font-medium">
                                    {category.name}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    {category.occurrences_count} lançamento(s)
                                </div>
                            </div>
                            <div className="flex gap-0.5">
                                <RenameDialog category={category} />
                                {category.occurrences_count === 0 && (
                                    <ConfirmDialog
                                        url={household.categories.destroy.url(
                                            category.id,
                                        )}
                                        title="Excluir categoria?"
                                        description={`A categoria “${category.name}” será excluída.`}
                                        confirmLabel="Excluir"
                                        trigger={
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                className="size-8 text-destructive"
                                                aria-label={`Excluir ${category.name}`}
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        }
                                    />
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}

function RenameDialog({ category }: { category: CategoryRow }) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    size="icon"
                    variant="ghost"
                    className="size-8"
                    aria-label={`Renomear ${category.name}`}
                >
                    <Pencil className="size-4" />
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Renomear categoria</DialogTitle>
                </DialogHeader>
                <Form
                    {...household.categories.update.form(category.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-1.5">
                                <Label htmlFor={`category-${category.id}`}>
                                    Nome
                                </Label>
                                <Input
                                    id={`category-${category.id}`}
                                    name="name"
                                    required
                                    maxLength={60}
                                    defaultValue={category.name}
                                />
                                <InputError message={errors.name} />
                            </div>
                            <DialogFooter>
                                <Button type="submit" disabled={processing}>
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

Categories.layout = {
    breadcrumbs: [
        {
            title: 'Configurações da casa',
            href: household.categories.index(),
        },
    ],
};
