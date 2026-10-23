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
            'daily_goal_seconds' => ['nullable', 'integer', 'min:1', 'max:86400'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'nome', 'daily_goal_seconds' => 'meta diária'];
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
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'daily_goal_seconds' => $this->input('daily_goal_seconds') ?: null,
        ]);
    }
}
