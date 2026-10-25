<?php

namespace App\Http\Requests\Shows;

use App\Http\Requests\Finance\FinanceRequest;
use App\Services\ShowChecklistService;
use Illuminate\Validation\Validator;

/**
 * Validates the household's show checklist. Items may be sent as text with
 * one item per line (as typed in the form) or as a list; blank lines and
 * repeated items (ignoring case and accents) are dropped.
 */
class ShowChecklistRequest extends FinanceRequest
{
    public const MAX_ITEMS = 100;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['present', 'array', 'max:'.self::MAX_ITEMS],
            'items.*' => ['required', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['items' => 'checklist', 'items.*' => 'item'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['items.max' => 'O checklist pode ter no máximo '.self::MAX_ITEMS.' itens.'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['items' => self::items($this->input('items'))]);
    }

    /**
     * Report a too-long item on the whole list, since the form edits it as
     * a single text field.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach ($validator->errors()->keys() as $key) {
                    if (str_starts_with($key, 'items.')) {
                        $validator->errors()->add('items', (string) $validator->errors()->first($key));

                        return;
                    }
                }
            },
        ];
    }

    /**
     * @return list<string>
     */
    public function labels(): array
    {
        /** @var list<string> */
        return $this->validated('items');
    }

    /**
     * Turn a list typed one item per line (or already a list) into a list of
     * labels, dropping blank lines, list numbering like "1." and repeats.
     *
     * @return list<mixed>
     */
    public static function items(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\R/u', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        $items = [];
        $seen = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                $items[] = $item;

                continue;
            }

            $item = trim((string) preg_replace('/^\s*\d{1,3}\s*[.)\-–]\s+/u', '', $item));
            $key = ShowChecklistService::key($item);

            if ($item !== '' && ! isset($seen[$key])) {
                $seen[$key] = true;
                $items[] = $item;
            }
        }

        return $items;
    }
}
