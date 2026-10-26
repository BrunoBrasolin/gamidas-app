<?php

namespace App\Http\Requests\Finance;

use App\Enums\ExpenseScope;
use App\Enums\RecurringKind;
use App\Models\RecurringExpense;
use App\Rules\MoneyAmount;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

class RecurringExpenseRequest extends FinanceRequest
{
    public function isCreating(): bool
    {
        return $this->isMethod('POST');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $template = $this->route('recurringExpense');

        $kind = $this->isCreating()
            ? $this->input('kind')
            : ($template instanceof RecurringExpense ? $template->kind->value : null);

        return [
            'kind' => [Rule::requiredIf($this->isCreating()), Rule::enum(RecurringKind::class)],
            'scope' => [Rule::requiredIf($this->isCreating()), Rule::enum(ExpenseScope::class)],
            'title' => ['required', 'string', 'max:120'],
            'is_variable' => ['nullable', 'boolean'],
            'amount' => [Rule::excludeIf($this->boolean('is_variable')), 'required', new MoneyAmount],
            'due_day' => ['required', 'integer', 'between:1,31'],
            'start_month' => $this->isCreating() ? $this->monthRules() : ['prohibited'],
            'end_month' => $kind === RecurringKind::Fixed->value ? $this->monthRules(false) : ['nullable'],
            'total_months' => $kind === RecurringKind::Temporary->value
                ? ['required', 'integer', 'between:1,120']
                : ['nullable'],
            'effective_month' => $this->isCreating() ? ['nullable'] : $this->monthRules(),
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'kind' => 'tipo',
            'scope' => 'responsabilidade',
            'title' => 'descrição',
            'is_variable' => 'valor variável',
            'amount' => 'valor mensal',
            'due_day' => 'dia de vencimento',
            'start_month' => 'mês de início',
            'end_month' => 'mês final',
            'total_months' => 'quantidade de meses',
            'effective_month' => 'aplicar a partir de',
            'notes' => 'observações',
        ];
    }

    /**
     * Variable templates have no amount: each month is filled in by hand.
     *
     * @return array{title: string, notes: ?string, amount_cents: int, is_variable: bool, due_day: int, end_month: ?CarbonImmutable, total_months: ?int}
     */
    public function toData(): array
    {
        $variable = $this->boolean('is_variable');

        return [
            'title' => trim((string) $this->validated('title')),
            'notes' => $this->nullableText('notes'),
            'amount_cents' => $variable ? 0 : $this->cents('amount'),
            'is_variable' => $variable,
            'due_day' => (int) $this->validated('due_day'),
            'end_month' => $this->month('end_month'),
            'total_months' => $this->nullableInt('total_months'),
        ];
    }

    /**
     * @return array{scope: ExpenseScope, kind: RecurringKind, title: string, notes: ?string, amount_cents: int, is_variable: bool, due_day: int, start_month: CarbonImmutable, end_month: ?CarbonImmutable, total_months: ?int}
     */
    public function toCreateData(): array
    {
        return [
            ...$this->toData(),
            'scope' => $this->scope(),
            'kind' => RecurringKind::from((string) $this->validated('kind')),
            'start_month' => $this->month('start_month') ?? throw new \LogicException('Missing start month.'),
        ];
    }
}
