<?php

namespace App\Http\Requests\Finance;

use Illuminate\Validation\Rule;

class ExpenseCategoryRequest extends FinanceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('expense_categories', 'name')
                    ->where('household_id', $this->householdId())
                    ->ignore($this->route('category')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'nome'];
    }
}
