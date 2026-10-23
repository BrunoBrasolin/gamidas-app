<?php

namespace App\Http\Requests\Time;

use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;

class TimeEntryRequest extends TimeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'task_id' => ['required', 'integer', $this->ownedTask()],
            'entry_date' => $this->dateRules(),
            'minutes' => ['required', 'integer', 'min:1', 'max:'.TimeEntry::MAX_MINUTES],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * New time can't go to an archived task. An entry that already belongs
     * to an archived task can still be corrected.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $entry = $this->route('entry');
            $task = $this->task();

            if ($task->isArchived() && (! $entry instanceof TimeEntry || $entry->task_id !== $task->id)) {
                $validator->errors()->add('task_id', 'Não é possível lançar tempo em uma tarefa arquivada.');
            }
        }];
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
            'description' => 'descrição',
        ];
    }

    /**
     * @return array{task_id: int, entry_date: CarbonImmutable, duration_minutes: int, description: ?string}
     */
    public function toData(): array
    {
        return [
            'task_id' => (int) $this->validated('task_id'),
            'entry_date' => $this->day('entry_date'),
            'duration_minutes' => (int) $this->validated('minutes'),
            'description' => $this->nullableText('description'),
        ];
    }
}
