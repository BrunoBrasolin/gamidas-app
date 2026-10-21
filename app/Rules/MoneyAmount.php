<?php

namespace App\Rules;

use App\Support\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Lang;

/**
 * Validates a decimal monetary amount such as "1234.56" within a cent range.
 */
class MoneyAmount implements ValidationRule
{
    public function __construct(
        private readonly int $minCents = 1,
        private readonly int $maxCents = Money::MAX_AMOUNT_CENTS,
    ) {
        //
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value)) {
            $fail('O campo :attribute deve ser um valor em reais válido.');

            return;
        }

        $value = (string) $value;

        if (preg_match(Money::DECIMAL_PATTERN, $value) !== 1) {
            $fail('O campo :attribute deve ser um valor em reais válido.');

            return;
        }

        $cents = Money::toCents($value);

        if ($cents < $this->minCents) {
            $fail(Lang::get('O campo :attribute deve ser de pelo menos :min.', ['min' => Money::format($this->minCents)]));
        } elseif ($cents > $this->maxCents) {
            $fail(Lang::get('O campo :attribute não pode ser maior que :max.', ['max' => Money::format($this->maxCents)]));
        }
    }
}
