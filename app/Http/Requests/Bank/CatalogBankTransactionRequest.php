<?php

namespace App\Http\Requests\Bank;

use App\Http\Requests\Finance\FinanceRequest;
use App\Rules\MoneyAmount;
use App\Services\Bank\CatalogChoice;
use App\Services\Bank\SplitPart;
use App\Support\Money;
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
            'occurrence_ids' => ['nullable', 'required_if:action,settlement', 'array', 'max:500'],
            'occurrence_ids.*' => ['integer', 'distinct'],
            'parts' => ['nullable', 'required_if:action,split', 'array', 'min:2', 'max:'.CatalogChoice::MAX_PARTS],
            'parts.*' => ['array'],
            'parts.*.action' => ['required', Rule::in(SplitPart::ACTIONS)],
            'parts.*.amount' => ['required', new MoneyAmount],
            'parts.*.title' => ['nullable', 'string', 'max:120'],
            'parts.*.occurrence_id' => ['nullable', 'required_if:parts.*.action,link', 'integer'],
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
            'occurrence_ids' => 'despesas',
            'occurrence_ids.*' => 'despesa',
            'parts' => 'partes',
            'parts.*.action' => 'tipo da parte',
            'parts.*.amount' => 'valor da parte',
            'parts.*.title' => 'título da parte',
            'parts.*.occurrence_id' => 'despesa da parte',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parts.required_if' => 'Divida o valor em pelo menos duas partes.',
            'parts.min' => 'Divida o valor em pelo menos duas partes.',
            'parts.*.occurrence_id.required_if' => 'Escolha a despesa desta parte.',
        ];
    }

    /**
     * A split is never remembered as a rule: its parts change every time.
     */
    public function toChoice(): CatalogChoice
    {
        $title = $this->nullableText('title');
        $action = (string) $this->validated('action');

        return new CatalogChoice(
            action: $action,
            title: $title,
            date: $this->validated('date') !== null ? $this->day('date') : null,
            notes: $this->nullableText('notes'),
            occurrenceId: $this->nullableInt('occurrence_id'),
            remember: $action !== 'split' && $this->boolean('remember'),
            autoApply: $this->has('auto_apply') ? $this->boolean('auto_apply') : null,
            rememberAmount: $this->boolean('remember_amount'),
            offsetTransactionId: $this->nullableInt('offset_transaction_id'),
            occurrenceIds: array_values(array_map(intval(...), (array) ($this->validated('occurrence_ids') ?? []))),
            parts: $action === 'split' ? $this->parts() : [],
        );
    }

    /**
     * @return list<SplitPart>
     */
    private function parts(): array
    {
        /** @var array<int, array{action: string, amount: string, title?: string|null, occurrence_id?: int|string|null}> $parts */
        $parts = (array) $this->validated('parts');

        return array_values(array_map(fn (array $part) => new SplitPart(
            action: $part['action'],
            amountCents: Money::toCents((string) $part['amount']),
            title: trim((string) ($part['title'] ?? '')) ?: null,
            occurrenceId: $part['action'] === 'link' && filled($part['occurrence_id'] ?? null) ? (int) $part['occurrence_id'] : null,
        ), $parts));
    }
}
