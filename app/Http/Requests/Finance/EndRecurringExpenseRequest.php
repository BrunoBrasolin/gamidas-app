<?php

namespace App\Http\Requests\Finance;

class EndRecurringExpenseRequest extends FinanceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'end_month' => $this->monthRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['end_month' => 'último mês'];
    }
}
