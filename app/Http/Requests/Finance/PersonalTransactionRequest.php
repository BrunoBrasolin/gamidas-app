<?php

namespace App\Http\Requests\Finance;

use App\Rules\MoneyAmount;
use Carbon\CarbonImmutable;

class PersonalTransactionRequest extends FinanceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'max:60'],
            'description' => ['required', 'string', 'max:120'],
            'amount' => ['required', new MoneyAmount],
            'transaction_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'category' => 'categoria',
            'description' => 'descrição',
            'amount' => 'valor',
            'transaction_date' => 'data',
            'notes' => 'observações',
        ];
    }

    /**
     * @return array{category: string, description: string, amount_cents: int, transaction_date: CarbonImmutable, notes: ?string}
     */
    public function toData(): array
    {
        return [
            'category' => trim((string) $this->validated('category')),
            'description' => trim((string) $this->validated('description')),
            'amount_cents' => $this->cents('amount'),
            'transaction_date' => $this->day('transaction_date'),
            'notes' => $this->nullableText('notes'),
        ];
    }
}
