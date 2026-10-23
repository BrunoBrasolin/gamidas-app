<?php

namespace App\Http\Requests\Time;

use App\Enums\TaskGoalType;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

class TaskRequest extends TimeRequest
{
    /**
     * Largest time target: 9.999 hours.
     */
    public const MAX_TARGET_MINUTES = 599_940;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $timeGoals = [TaskGoalType::Time->value, TaskGoalType::TimeAndOutcome->value];

        return [
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'goal_type' => ['required', Rule::enum(TaskGoalType::class)],
            'target_minutes' => ['nullable', Rule::requiredIf(in_array($this->input('goal_type'), $timeGoals, true)), 'integer', 'min:1', 'max:'.self::MAX_TARGET_MINUTES],
            'outcome' => ['nullable', 'string', 'max:255'],
            'deadline' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'título',
            'description' => 'descrição',
            'goal_type' => 'tipo de meta',
            'target_minutes' => 'meta de tempo',
            'outcome' => 'resultado esperado',
            'deadline' => 'prazo',
        ];
    }

    /**
     * @return array{title: string, description: ?string, goal_type: TaskGoalType, target_minutes: ?int, outcome: ?string, deadline: ?CarbonImmutable}
     */
    public function toData(): array
    {
        $goal = TaskGoalType::from((string) $this->validated('goal_type'));

        return [
            'title' => trim((string) $this->validated('title')),
            'description' => $this->nullableText('description'),
            'goal_type' => $goal,
            'target_minutes' => $goal->hasTimeTarget() ? $this->nullableInt('target_minutes') : null,
            'outcome' => $goal->hasOutcome() ? $this->nullableText('outcome') : null,
            'deadline' => $this->nullableText('deadline') === null ? null : $this->day('deadline'),
        ];
    }
}
