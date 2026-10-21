import { NativeSelect } from '@/components/ui/native-select';
import type { Category } from '@/types';

export function CategorySelect({
    id,
    categories,
    defaultValue,
    name = 'expense_category_id',
    invalid = false,
}: {
    id: string;
    categories: Category[];
    defaultValue?: number | null;
    name?: string;
    invalid?: boolean;
}) {
    return (
        <NativeSelect
            id={id}
            name={name}
            defaultValue={defaultValue ?? ''}
            aria-invalid={invalid}
        >
            <option value="">Sem categoria</option>
            {categories.map((category) => (
                <option key={category.id} value={category.id}>
                    {category.name}
                </option>
            ))}
        </NativeSelect>
    );
}
