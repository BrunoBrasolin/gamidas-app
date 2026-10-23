<?php

namespace App\Http\Requests\Time;

use App\Models\TimeEntry;

class SheetCellRequest extends TimeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'task_id' => ['required', 'integer', $this->ownedTask()],
            'entry_date' => $this->dateRules(),
            'minutes' => ['required', 'integer', 'min:0', 'max:'.TimeEntry::MAX_MINUTES],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'task_id' => 'tarefa',
            'entry_date' => 'data',
            'minutes' => 'duração',
        ];
    }
}
