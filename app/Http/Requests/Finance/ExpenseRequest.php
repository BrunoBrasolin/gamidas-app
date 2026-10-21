<?php

namespace App\Http\Requests\Finance;

use App\Enums\ExpenseScope;
use App\Rules\MoneyAmount;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

class ExpenseRequest extends FinanceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scope' => [Rule::requiredIf($this->isMethod('POST')), Rule::enum(ExpenseScope::class)],
            'title' => ['required', 'string', 'max:120'],
            'expense_category_id' => ['nullable', 'integer', $this->existsInHousehold('expense_categories')],
            'amount' => ['required', new MoneyAmount],
            'billing_month' => $this->monthRules(),
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
            'expense_category_id' => 'categoria',
            'amount' => 'valor',
            'billing_month' => 'mês de referência',
            'due_date' => 'vencimento',
            'notes' => 'observações',
        ];
    }

    /**
     * @return array{title: string, notes: ?string, expense_category_id: ?int, amount_cents: int, billing_month: CarbonImmutable, due_date: CarbonImmutable}
     */
    public function toData(): array
    {
        return [
            'title' => trim((string) $this->validated('title')),
            'notes' => $this->nullableText('notes'),
            'expense_category_id' => $this->nullableInt('expense_category_id'),
            'amount_cents' => $this->cents('amount'),
            'billing_month' => $this->month('billing_month') ?? throw new \LogicException('Missing billing month.'),
            'due_date' => $this->day('due_date'),
        ];
    }
}
