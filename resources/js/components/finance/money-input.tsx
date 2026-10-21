import { useState } from 'react';
import type { ComponentProps } from 'react';
import { Input } from '@/components/ui/input';
import { centsToDecimal, formatMoney } from '@/lib/format';

type MoneyInputProps = Omit<
    ComponentProps<'input'>,
    'name' | 'value' | 'defaultValue' | 'onChange' | 'type'
> & {
    name: string;
    defaultCents?: number | null;
    onCentsChange?: (cents: number) => void;
};

/**
 * Currency input with a cents mask: typing "123456" shows "R$ 1.234,56".
 * The form submits the unambiguous decimal string ("1234.56") in a hidden
 * input, which the server converts to integer cents.
 */
export function MoneyInput({
    name,
    defaultCents = null,
    onCentsChange,
    ...props
}: MoneyInputProps) {
    const [cents, setCents] = useState<number | null>(defaultCents);

    function update(raw: string) {
        const digits = raw.replace(/\D/g, '').replace(/^0+/, '').slice(0, 10);
        const next = digits === '' ? null : Number(digits);

        setCents(next);
        onCentsChange?.(next ?? 0);
    }

    return (
        <>
            <Input
                {...props}
                type="text"
                inputMode="numeric"
                autoComplete="off"
                placeholder={props.placeholder ?? 'R$ 0,00'}
                value={cents === null ? '' : formatMoney(cents)}
                onChange={(event) => update(event.target.value)}
            />
            <input
                type="hidden"
                name={name}
                value={cents === null ? '' : centsToDecimal(cents)}
            />
        </>
    );
}
