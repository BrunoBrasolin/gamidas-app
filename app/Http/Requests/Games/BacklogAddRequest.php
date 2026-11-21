<?php

namespace App\Http\Requests\Games;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Several games of the library to add to the "para zerar" list at once, in
 * order; optionally already finished, on a given day.
 */
class BacklogAddRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'game_ids' => ['required', 'array', 'min:1', 'max:500'],
            'game_ids.*' => ['integer', 'distinct'],
            'finished' => ['boolean'],
            'finished_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1990-01-01', 'before_or_equal:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['game_ids' => 'jogos', 'finished_on' => 'data em que zerou'];
    }

    /**
     * @return list<int>
     */
    public function gameIds(): array
    {
        return array_values(array_map('intval', (array) $this->validated('game_ids')));
    }

    public function finished(): bool
    {
        return $this->boolean('finished');
    }

    public function finishedOn(): ?CarbonImmutable
    {
        $date = $this->validated('finished_on');

        return is_string($date) ? CarbonImmutable::createFromFormat('!Y-m-d', $date, (string) config('app.timezone')) ?: null : null;
    }
}
