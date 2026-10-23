<?php

namespace App\Http\Requests\Time;

use App\Models\DailyNote;

class DailyNoteRequest extends TimeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content' => ['nullable', 'string', 'max:'.DailyNote::MAX_LENGTH],
            'base_revision' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'content' => 'nota',
            'base_revision' => 'revisão',
        ];
    }
}
