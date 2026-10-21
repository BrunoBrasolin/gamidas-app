<?php

namespace App\Http\Requests\Finance;

use Illuminate\Validation\Rule;

class CreditCardRequest extends FinanceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('credit_cards', 'name')
                    ->where('household_id', $this->householdId())
                    ->ignore($this->route('creditCard')),
            ],
            'last_four_digits' => ['nullable', 'digits:4'],
            'holder_user_id' => [
                'nullable', 'integer',
                Rule::exists('household_members', 'user_id')->where('household_id', $this->householdId()),
            ],
            'due_day' => ['required', 'integer', 'between:1,31'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome do cartão',
            'last_four_digits' => 'últimos 4 dígitos',
            'holder_user_id' => 'titular',
            'due_day' => 'dia de vencimento',
        ];
    }

    /**
     * @return array{name: string, last_four_digits: ?string, holder_user_id: ?int, due_day: int, is_active: bool}
     */
    public function toData(): array
    {
        return [
            'name' => trim((string) $this->validated('name')),
            'last_four_digits' => $this->nullableText('last_four_digits'),
            'holder_user_id' => $this->nullableInt('holder_user_id'),
            'due_day' => (int) $this->validated('due_day'),
            'is_active' => $this->boolean('is_active', true),
        ];
    }
}
