<?php

namespace App\Http\Requests\Attention;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Someone entering a room from the public page.
 */
class JoinAttentionGameRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nickname' => ['required', 'string', 'min:2', 'max:24', 'regex:/^[\pL\pN][\pL\pN _.\-]*$/u'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['nickname' => 'apelido'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['nickname.regex' => 'Use apenas letras, números, espaço, ponto, traço ou sublinhado.'];
    }

    public function nickname(): string
    {
        return (string) preg_replace('/\s+/u', ' ', (string) $this->validated('nickname'));
    }
}
