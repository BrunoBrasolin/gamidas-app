import { Home, User } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { Scope } from '@/types';

/**
 * Chooses whether an expense is shared by the household (split by salaries)
 * or belongs only to the current user.
 */
export function ScopeField({
    defaultValue = 'household',
    error,
}: {
    defaultValue?: Scope;
    error?: string;
}) {
    const options: {
        value: Scope;
        label: string;
        hint: string;
        icon: typeof Home;
    }[] = [
        {
            value: 'household',
            label: 'Despesa da casa',
            hint: 'Dividida pela proporção dos salários',
            icon: Home,
        },
        {
            value: 'personal',
            label: 'Despesa pessoal',
            hint: 'Só sua, visível apenas para você',
            icon: User,
        },
    ];

    return (
        <fieldset className="grid gap-1.5">
            <legend className="mb-1.5 text-sm font-medium">
                Responsabilidade
            </legend>
            <div className="grid gap-2 sm:grid-cols-2">
                {options.map((option) => (
                    <label
                        key={option.value}
                        className={cn(
                            'flex cursor-pointer items-start gap-2 rounded-md border p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-muted',
                            'has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50',
                        )}
                    >
                        <input
                            type="radio"
                            name="scope"
                            value={option.value}
                            defaultChecked={defaultValue === option.value}
                            className="sr-only"
                        />
                        <option.icon
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden
                        />
                        <span>
                            <span className="block font-medium">
                                {option.label}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {option.hint}
                            </span>
                        </span>
                    </label>
                ))}
            </div>
            {error && (
                <p className="text-sm text-red-600 dark:text-red-400">
                    {error}
                </p>
            )}
        </fieldset>
    );
}
