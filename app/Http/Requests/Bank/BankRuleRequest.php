<?php

namespace App\Http\Requests\Bank;

use App\Enums\BankRuleAction;
use App\Enums\BankRuleMatch;
use App\Http\Requests\Finance\FinanceRequest;
use App\Models\RecurringExpense;
use App\Rules\MoneyAmount;
use App\Services\Bank\TransactionClassifier;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A rule written or edited on the rules page. The text is compared with the
 * transaction's counterparty (or description) without accents, case or
 * punctuation; an optional amount restricts it to transactions of exactly
 * that value.
 */
class BankRuleRequest extends FinanceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:160'],
            'match_mode' => ['required', Rule::enum(BankRuleMatch::class)],
            'amount' => ['nullable', new MoneyAmount],
            'action' => ['required', Rule::enum(BankRuleAction::class)],
            'recurring_expense_id' => ['nullable', 'required_if:action,recurring', 'integer', $this->existsInHousehold('recurring_expenses')],
            'title' => ['nullable', 'string', 'max:120'],
            'auto_apply' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'label' => 'contraparte ou descrição',
            'match_mode' => 'comparação',
            'amount' => 'valor',
            'action' => 'classificação',
            'recurring_expense_id' => 'despesa recorrente',
            'title' => 'título',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->pattern() === '') {
                $validator->errors()->add('label', 'Informe letras ou números para comparar.');
            }

            $recurring = $this->nullableInt('recurring_expense_id');

            // A personal recurring expense of the other member is not visible.
            if ($recurring !== null && $this->action() === BankRuleAction::Recurring
                && ! RecurringExpense::query()->visibleTo($this->user())->whereKey($recurring)->exists()) {
                $validator->errors()->add('recurring_expense_id', 'Escolha uma despesa recorrente da casa ou sua.');
            }
        }];
    }

    public function pattern(): string
    {
        return TransactionClassifier::normalize((string) $this->validated('label'));
    }

    public function action(): BankRuleAction
    {
        return BankRuleAction::from((string) $this->validated('action'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toData(): array
    {
        $action = $this->action();

        return [
            'label' => trim((string) $this->validated('label')),
            'pattern' => $this->pattern(),
            'match_mode' => BankRuleMatch::from((string) $this->validated('match_mode')),
            'amount_cents' => filled($this->validated('amount')) ? $this->cents('amount') : null,
            'action' => $action,
            'recurring_expense_id' => $action === BankRuleAction::Recurring ? $this->nullableInt('recurring_expense_id') : null,
            'title' => in_array($action, [BankRuleAction::Recurring, BankRuleAction::Ignore], true) ? null : $this->nullableText('title'),
            'auto_apply' => $this->boolean('auto_apply'),
        ];
    }
}
