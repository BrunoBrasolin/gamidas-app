<?php

namespace App\Http\Requests\Finance;

use App\Rules\MoneyAmount;
use App\Support\Money;
use Illuminate\Validation\Rule;

class UpdateSalariesRequest extends FinanceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'salaries' => ['required', 'array', 'min:1'],
            'salaries.*.user_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('household_members', 'user_id')->where('household_id', $this->householdId()),
            ],
            'salaries.*.amount' => ['required', new MoneyAmount(0, Money::MAX_SALARY_CENTS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'salaries.*.user_id' => 'membro',
            'salaries.*.amount' => 'salário',
        ];
    }

    /**
     * @return array<int, int> Salaries in cents keyed by user id.
     */
    public function salaries(): array
    {
        $salaries = [];

        foreach ((array) $this->validated('salaries') as $row) {
            $salaries[(int) $row['user_id']] = Money::toCents((string) $row['amount']);
        }

        return $salaries;
    }
}
