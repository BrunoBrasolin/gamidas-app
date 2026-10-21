import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';

export function FilterSelect({
    id,
    label,
    value,
    options,
    onChange,
}: {
    id: string;
    label: string;
    value: string | number;
    options: [string, string][];
    onChange: (value: string) => void;
}) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <NativeSelect
                id={id}
                value={String(value)}
                onChange={(event) => onChange(event.target.value)}
            >
                {options.map(([optionValue, optionLabel]) => (
                    <option key={optionValue} value={optionValue}>
                        {optionLabel}
                    </option>
                ))}
            </NativeSelect>
        </div>
    );
}
