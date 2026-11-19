<?php

namespace App\Http\Requests\Bank;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A Pluggy item to attach: created by the Connect widget, or copied from the
 * Pluggy dashboard (Meu Pluggy).
 */
class StoreBankConnectionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'item_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['item_id' => 'ID da conexão'];
    }

    public function itemId(): string
    {
        return trim((string) $this->validated('item_id'));
    }
}
