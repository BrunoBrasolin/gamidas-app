<?php

namespace App\Http\Requests\ComplementaryHours;

use App\Support\Hours;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ComplementaryHoursGoalRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['hours' => ['required', 'string', 'max:12']];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['hours' => 'horas necessárias'];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $validator->errors()->has('hours') && Hours::parse((string) $this->input('hours')) === null) {
                $validator->errors()->add('hours', 'Informe as horas como 200, 1,5 ou 1:30.');
            }
        }];
    }

    public function minutes(): int
    {
        return (int) Hours::parse((string) $this->validated('hours'));
    }
}
