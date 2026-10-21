<?php

namespace App\Http\Requests\Finance;

class ReopenPaymentRequest extends FinanceRequest
{
    protected string $routeAbility = 'reopenPayment';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reason' => 'motivo'];
    }
}
