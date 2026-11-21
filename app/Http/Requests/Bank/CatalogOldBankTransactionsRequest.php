<?php

namespace App\Http\Requests\Bank;

use App\Http\Requests\Finance\FinanceRequest;

/**
 * The month before which the inbox is cleared at once ("Lançar antigas").
 */
class CatalogOldBankTransactionsRequest extends FinanceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'before' => $this->monthRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'before' => 'mês',
        ];
    }
}
