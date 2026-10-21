<?php

namespace App\Http\Requests\Finance;

use App\Enums\ExpenseScope;
use App\Rules\MoneyAmount;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

class ExpenseRequest extends FinanceRequest
{
    public const MAX_INSTALLMENTS = 120;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scope' => [Rule::requiredIf($this->isMethod('POST')), Rule::enum(ExpenseScope::class)],
            'title' => ['required', 'string', 'max:120'],
            'amount' => ['required', new MoneyAmount],
            'installments' => [Rule::excludeIf(! $this->isMethod('POST')), 'nullable', 'integer', 'min:1', 'max:'.self::MAX_INSTALLMENTS],
            'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'descrição',
            'amount' => 'valor',
            'installments' => 'parcelas',
            'due_date' => 'vencimento',
            'notes' => 'observações',
        ];
    }

    /**
     * The billing month is not part of the payload: it is the month of the
     * due date.
     *
     * @return array{title: string, notes: ?string, amount_cents: int, due_date: CarbonImmutable}
     */
    public function toData(): array
    {
        return [
            'title' => trim((string) $this->validated('title')),
            'notes' => $this->nullableText('notes'),
            'amount_cents' => $this->cents('amount'),
            'due_date' => $this->day('due_date'),
        ];
    }

    public function installments(): int
    {
        return $this->nullableInt('installments') ?? 1;
    }
}
