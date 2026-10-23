<?php

namespace App\Http\Requests\Time;

use App\Models\TimeCategory;
use Illuminate\Validation\Rule;

class TimeCategoryRequest extends TimeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->route('category');

        return [
            'name' => [
                'required',
                'string',
                'max:80',
                Rule::unique('time_categories', 'name')
                    ->where('user_id', $this->user()?->id)
                    ->ignore($current instanceof TimeCategory ? $current->id : null),
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

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.unique' => 'Você já tem uma categoria com este nome.'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }
}
