<?php

namespace App\Http\Requests\Finance;

use App\Enums\ExpenseScope;
use App\Enums\PurchaseMode;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreditCardPurchaseRequest extends FinanceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'credit_card_id' => ['required', 'integer', $this->existsInHousehold('credit_cards')],
            'scope' => ['required', Rule::enum(ExpenseScope::class)],
            'description' => ['required', 'string', 'max:120'],
            'expense_category_id' => ['nullable', 'integer', $this->existsInHousehold('expense_categories')],
            'purchase_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'mode' => ['required', Rule::enum(PurchaseMode::class)],
            'amount' => ['required', new MoneyAmount],
            'installment_count' => ['required', 'integer', 'between:1,120'],
            'first_billing_month' => $this->monthRules(),
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $cents = $this->cents('amount');
                $count = (int) $this->validated('installment_count');

                if ($this->validated('mode') === PurchaseMode::Installments->value && $cents < $count) {
                    $validator->errors()->add('amount', 'O valor total precisa ser de pelo menos R$ 0,01 por parcela.');
                }

                if ($this->validated('mode') === PurchaseMode::FixedMonthly->value && $cents * $count > Money::MAX_AMOUNT_CENTS) {
                    $validator->errors()->add('amount', 'O valor total da compra excede o limite permitido.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'credit_card_id' => 'cartão',
            'scope' => 'responsabilidade',
            'description' => 'descrição',
            'expense_category_id' => 'categoria',
            'purchase_date' => 'data da compra',
            'mode' => 'forma de cobrança',
            'amount' => 'valor',
            'installment_count' => 'quantidade de parcelas',
            'first_billing_month' => 'primeira fatura',
            'notes' => 'observações',
        ];
    }

    /**
     * @return array{credit_card_id: int, scope: ExpenseScope, expense_category_id: ?int, description: string, notes: ?string, purchase_date: CarbonImmutable, mode: PurchaseMode, amount_cents: int, installment_count: int, first_billing_month: CarbonImmutable}
     */
    public function toData(): array
    {
        return [
            'credit_card_id' => (int) $this->validated('credit_card_id'),
            'scope' => $this->scope(),
            'expense_category_id' => $this->nullableInt('expense_category_id'),
            'description' => trim((string) $this->validated('description')),
            'notes' => $this->nullableText('notes'),
            'purchase_date' => $this->day('purchase_date'),
            'mode' => PurchaseMode::from((string) $this->validated('mode')),
            'amount_cents' => $this->cents('amount'),
            'installment_count' => (int) $this->validated('installment_count'),
            'first_billing_month' => $this->month('first_billing_month') ?? throw new \LogicException('Missing first billing month.'),
        ];
    }
}
