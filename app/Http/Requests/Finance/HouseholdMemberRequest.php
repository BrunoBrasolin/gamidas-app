<?php

namespace App\Http\Requests\Finance;

use App\Models\HouseholdMember;
use Illuminate\Validation\Rule;

class HouseholdMemberRequest extends FinanceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payment_label_preposition' => ['required', Rule::in(HouseholdMember::PREPOSITIONS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['payment_label_preposition' => 'tratamento'];
    }
}
