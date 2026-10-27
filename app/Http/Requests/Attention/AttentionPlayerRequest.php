<?php

namespace App\Http\Requests\Attention;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A request from a player's phone, identified by the token it got on joining.
 */
class AttentionPlayerRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:40'],
        ];
    }

    public function token(): string
    {
        return (string) $this->validated('token');
    }
}
