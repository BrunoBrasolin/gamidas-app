<?php

namespace App\Http\Requests\Attention;

use App\Http\Requests\Finance\FinanceRequest;

/**
 * Validates a new room of the attention game.
 */
class AttentionGameRequest extends FinanceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['title' => 'nome da sala'];
    }
}
