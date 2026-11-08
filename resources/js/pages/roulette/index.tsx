import { Form, Head, router } from '@inertiajs/react';
import {
    Dices,
    IceCreamCone,
    Moon,
    PartyPopper,
    Plus,
    Trash2,
    Utensils,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useRef, useState } from 'react';
import { FormField } from '@/components/finance/form-field';
import { PageHeader } from '@/components/finance/page-header';
import { RouletteWheel } from '@/components/roulette/roulette-wheel';
import type { RouletteWheelHandle } from '@/components/roulette/roulette-wheel';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import roulette from '@/routes/roulette';
import type { RouletteCategory, RouletteCategoryValue } from '@/types';

const ICONS: Record<RouletteCategoryValue, LucideIcon> = {
    lunch: Utensils,
    dessert: IceCreamCone,
    dinner: Moon,
};

const MIN_PLACES = 2;
const WEIGHTS = Array.from({ length: 10 }, (_, index) => index + 1);

/** "Peso 3" with a hint of what it means compared with the default. */
function weightLabel(weight: number): string {
    return weight === 1
        ? 'Peso 1 (normal)'
        : `Peso ${weight} (${weight}× mais chance)`;
}

export default function RoulettePage({
    categories,
}: {
    categories: RouletteCategory[];
}) {
    const [selected, setSelected] = useState<RouletteCategoryValue>(
        categories[0].value,
    );
    const [spinning, setSpinning] = useState(false);
    const [winner, setWinner] = useState<{ id: number; name: string } | null>(
        null,
    );
    const wheel = useRef<RouletteWheelHandle>(null);

    const category = categories.find((item) => item.value === selected)!;
    const places = category.places;
    const totalWeight = places.reduce((sum, place) => sum + place.weight, 0);
    const canSpin = places.length >= MIN_PLACES && !spinning;
    // The result only counts while the place is still on the wheel.
    const shown =
        winner && places.some((place) => place.id === winner.id)
            ? winner
            : null;

    async function spin() {
        if (!wheel.current || !canSpin) {
            return;
        }

        setWinner(null);
        setSpinning(true);

        const result = await wheel.current.spin();

        setWinner({ id: result.id, name: result.name });
        setSpinning(false);
    }

    return (
        <>
            <Head title="Roleta" />
            <div className="flex flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Roleta"
                    description="Sem ideia do que comer? Escolha a categoria e deixe a roleta decidir. Todos da casa podem adicionar lugares e dar mais peso aos preferidos."
                >
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        value={selected}
                        disabled={spinning}
                        onValueChange={(value) => {
                            if (value) {
                                setSelected(value as RouletteCategoryValue);
                            }
                        }}
                        aria-label="Categoria"
                    >
                        {categories.map((item) => {
                            const Icon = ICONS[item.value];

                            return (
                                <ToggleGroupItem
                                    key={item.value}
                                    value={item.value}
                                    className="px-3"
                                >
                                    <Icon aria-hidden />
                                    {item.label}
                                </ToggleGroupItem>
                            );
                        })}
                    </ToggleGroup>
                </PageHeader>

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,24rem)]">
                    <Card>
                        <CardContent className="flex flex-col items-center gap-6">
                            <RouletteWheel
                                ref={wheel}
                                places={places}
                                highlightId={shown?.id ?? null}
                            />

                            <div className="flex min-h-24 flex-col items-center justify-center gap-3 text-center">
                                {shown ? (
                                    <div
                                        key={shown.id}
                                        className="flex animate-in flex-col items-center gap-1 duration-500 zoom-in-90 fade-in"
                                        role="status"
                                    >
                                        <PartyPopper
                                            className="size-6 text-amber-500"
                                            aria-hidden
                                        />
                                        <p className="text-sm text-muted-foreground">
                                            {category.label}: a roleta escolheu
                                        </p>
                                        <p className="text-2xl font-bold tracking-tight">
                                            {shown.name}
                                        </p>
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        {places.length < MIN_PLACES
                                            ? `Adicione pelo menos ${MIN_PLACES} lugares em ${category.label.toLowerCase()} para girar.`
                                            : spinning
                                              ? 'Girando…'
                                              : 'Pronto para girar!'}
                                    </p>
                                )}
                                <Button
                                    size="lg"
                                    onClick={spin}
                                    disabled={!canSpin}
                                >
                                    <Dices />
                                    {shown ? 'Girar de novo' : 'Girar roleta'}
                                </Button>
                            </div>
                        </CardContent>
                    </Card>

                    <Card className="self-start">
                        <CardHeader>
                            <CardTitle>{category.label}</CardTitle>
                            <CardDescription>
                                {places.length === 0
                                    ? 'Nenhum lugar ainda.'
                                    : `${places.length} ${places.length === 1 ? 'lugar' : 'lugares'} na roleta.`}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <Form
                                {...roulette.places.store.form()}
                                options={{ preserveScroll: true }}
                                resetOnSuccess={['name']}
                                className="grid gap-3"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="category"
                                            value={selected}
                                        />
                                        <FormField
                                            id="place-name"
                                            label="Novo lugar"
                                            error={
                                                errors.name ?? errors.category
                                            }
                                            className="[&>label]:sr-only"
                                        >
                                            <Input
                                                id="place-name"
                                                name="name"
                                                required
                                                maxLength={80}
                                                placeholder="Ex.: Cantina da Nonna"
                                                autoComplete="off"
                                            />
                                        </FormField>
                                        <div className="flex items-start gap-2">
                                            <FormField
                                                id="place-weight"
                                                label="Peso"
                                                error={errors.weight}
                                                className="flex-1 [&>label]:sr-only"
                                            >
                                                <NativeSelect
                                                    id="place-weight"
                                                    name="weight"
                                                    defaultValue="1"
                                                    title="Quanto maior o peso, mais chance de ser sorteado"
                                                >
                                                    {WEIGHTS.map((weight) => (
                                                        <option
                                                            key={weight}
                                                            value={weight}
                                                        >
                                                            {weightLabel(
                                                                weight,
                                                            )}
                                                        </option>
                                                    ))}
                                                </NativeSelect>
                                            </FormField>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                {processing ? (
                                                    <Spinner />
                                                ) : (
                                                    <Plus />
                                                )}
                                                Adicionar
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>

                            {places.length > 0 && (
                                <ul className="divide-y rounded-md border">
                                    {places.map((place) => (
                                        <li
                                            key={place.id}
                                            className="flex items-center justify-between gap-2 px-3 py-2"
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium">
                                                    {place.name}
                                                </p>
                                                {place.created_by && (
                                                    <p className="text-xs text-muted-foreground">
                                                        por {place.created_by}
                                                    </p>
                                                )}
                                            </div>
                                            <div className="flex items-center gap-1">
                                                <span
                                                    className="w-9 text-right text-xs text-muted-foreground tabular-nums"
                                                    title="Chance de ser sorteado"
                                                >
                                                    {Math.round(
                                                        (place.weight /
                                                            totalWeight) *
                                                            100,
                                                    )}
                                                    %
                                                </span>
                                                <NativeSelect
                                                    aria-label={`Peso de ${place.name}`}
                                                    title="Peso: quanto maior, mais chance de ser sorteado"
                                                    className="h-8 w-16 px-2"
                                                    value={place.weight}
                                                    disabled={spinning}
                                                    onChange={(event) =>
                                                        router.put(
                                                            roulette.places.update.url(
                                                                place.id,
                                                            ),
                                                            {
                                                                weight: Number(
                                                                    event.target
                                                                        .value,
                                                                ),
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    {WEIGHTS.map((weight) => (
                                                        <option
                                                            key={weight}
                                                            value={weight}
                                                        >
                                                            {weight}×
                                                        </option>
                                                    ))}
                                                </NativeSelect>
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                    aria-label={`Remover ${place.name}`}
                                                    title="Remover"
                                                    disabled={spinning}
                                                    onClick={() =>
                                                        router.delete(
                                                            roulette.places.destroy.url(
                                                                place.id,
                                                            ),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <Trash2 />
                                                </Button>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

RoulettePage.layout = {
    breadcrumbs: [{ title: 'Roleta', href: roulette.index() }],
};
