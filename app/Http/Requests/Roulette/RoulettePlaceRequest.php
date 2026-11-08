<?php

namespace App\Http\Requests\Roulette;

use App\Enums\RouletteCategory;
use App\Http\Requests\Finance\FinanceRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a new roulette option. A place can only be in a category once,
 * ignoring case, so the wheel never has two identical slices.
 */
class RoulettePlaceRequest extends FinanceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(RouletteCategory::class)],
            'name' => ['required', 'string', 'max:80'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['category' => 'categoria', 'name' => 'nome'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.required' => 'Informe o nome do lugar.'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) preg_replace('/\s+/u', ' ', (string) $this->input('name')))]);
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $exists = $this->household()->roulettePlaces()
                    ->where('category', $this->validated('category'))
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $this->validated('name'))])
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('name', 'Este lugar já está na roleta desta categoria.');
                }
            },
        ];
    }

    public function category(): RouletteCategory
    {
        return RouletteCategory::from((string) $this->validated('category'));
    }
}
