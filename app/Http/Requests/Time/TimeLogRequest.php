<?php

namespace App\Http\Requests\Time;

use App\Models\TimeLog;

class TimeLogRequest extends TimeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', $this->ownedCategory()],
            'log_date' => $this->dateRules(),
            'seconds' => ['required', 'integer', 'min:0', 'max:'.TimeLog::MAX_SECONDS],
            'note' => ['sometimes', 'nullable', 'string', 'max:'.TimeLog::MAX_NOTE_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'category_id' => 'categoria',
            'log_date' => 'data',
            'seconds' => 'duração',
            'note' => 'nota',
        ];
    }
}
