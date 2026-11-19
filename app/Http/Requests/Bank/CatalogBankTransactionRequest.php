<?php

namespace App\Http\Requests\Bank;

use App\Http\Requests\Finance\FinanceRequest;
use App\Services\Bank\CatalogChoice;
use Illuminate\Validation\Rule;

/**
 * How the owner catalogues an imported transaction. The transaction in the
 * route is authorized (owner only) before validation.
 */
class CatalogBankTransactionRequest extends FinanceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(CatalogChoice::ACTIONS)],
            'title' => ['nullable', 'string', 'max:120'],
            'date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'occurrence_id' => ['nullable', 'required_if:action,link', 'integer'],
            'offset_transaction_id' => ['nullable', 'required_if:action,offset', 'integer'],
            'remember' => ['boolean'],
            'remember_amount' => ['boolean'],
            'auto_apply' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'action' => 'tipo',
            'title' => 'título',
            'date' => 'data',
            'notes' => 'observações',
            'occurrence_id' => 'despesa',
            'offset_transaction_id' => 'transação',
        ];
    }

    public function toChoice(): CatalogChoice
    {
        $title = $this->nullableText('title');

        return new CatalogChoice(
            action: (string) $this->validated('action'),
            title: $title,
            date: $this->validated('date') !== null ? $this->day('date') : null,
            notes: $this->nullableText('notes'),
            occurrenceId: $this->nullableInt('occurrence_id'),
            remember: $this->boolean('remember'),
            autoApply: $this->has('auto_apply') ? $this->boolean('auto_apply') : null,
            rememberAmount: $this->boolean('remember_amount'),
            offsetTransactionId: $this->nullableInt('offset_transaction_id'),
        );
    }
}
