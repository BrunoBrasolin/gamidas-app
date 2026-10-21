<?php

namespace App\Http\Requests\Calendar;

use App\Enums\EventColor;
use App\Enums\EventIcon;
use App\Enums\RecurrenceFrequency;
use App\Enums\RecurrenceScope;
use App\Http\Requests\Finance\FinanceRequest;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

class CalendarEventRequest extends FinanceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'event_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'event_time' => ['nullable', 'date_format:H:i'],
            'description' => ['nullable', 'string', 'max:2000'],
            'color' => ['required', Rule::enum(EventColor::class)],
            'icon' => ['required', Rule::enum(EventIcon::class)],
            'recurrence_frequency' => ['nullable', Rule::enum(RecurrenceFrequency::class)],
            'recurrence_interval' => ['nullable', 'integer', 'min:1', 'max:99'],
            'recurrence_weekdays' => ['nullable', 'array', 'max:7'],
            'recurrence_weekdays.*' => ['integer', 'between:0,6'],
            'recurrence_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:event_date', 'before_or_equal:2100-12-31'],
            'scope' => ['nullable', Rule::enum(RecurrenceScope::class)],
            'occurrence' => ['nullable', 'required_if:scope,occurrence', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'título',
            'event_date' => 'data',
            'event_time' => 'horário',
            'description' => 'descrição',
            'color' => 'cor',
            'icon' => 'ícone',
            'recurrence_frequency' => 'repetição',
            'recurrence_interval' => 'intervalo',
            'recurrence_weekdays' => 'dias da semana',
            'recurrence_weekdays.*' => 'dia da semana',
            'recurrence_until' => 'data de término',
            'occurrence' => 'ocorrência',
        ];
    }

    /**
     * The date of the single occurrence being edited, when the edit is not
     * meant for the whole series.
     */
    public function occurrence(): ?CarbonImmutable
    {
        return $this->validated('scope') === RecurrenceScope::Occurrence->value ? $this->day('occurrence') : null;
    }

    /**
     * The event values plus its recurrence rule, for a new event or a series.
     *
     * @return array{title: string, event_date: CarbonImmutable, event_time: ?string, description: ?string, color: EventColor, icon: EventIcon, recurrence_frequency: ?RecurrenceFrequency, recurrence_interval: int, recurrence_weekdays: ?string, recurrence_until: ?CarbonImmutable}
     */
    public function toSeriesData(): array
    {
        $frequency = RecurrenceFrequency::tryFrom((string) $this->validated('recurrence_frequency'));
        $weekdays = array_unique(array_map(intval(...), (array) $this->validated('recurrence_weekdays')));
        sort($weekdays);

        return [
            ...$this->toData(),
            'recurrence_frequency' => $frequency,
            'recurrence_interval' => $frequency === null ? 1 : ($this->nullableInt('recurrence_interval') ?? 1),
            'recurrence_weekdays' => $frequency === RecurrenceFrequency::Weekly && $weekdays !== [] ? implode(',', $weekdays) : null,
            'recurrence_until' => $frequency !== null && $this->nullableText('recurrence_until') !== null ? $this->day('recurrence_until') : null,
        ];
    }

    /**
     * The values of a single event or occurrence, without recurrence.
     *
     * @return array{title: string, event_date: CarbonImmutable, event_time: ?string, description: ?string, color: EventColor, icon: EventIcon}
     */
    public function toData(): array
    {
        return [
            'title' => trim((string) $this->validated('title')),
            'event_date' => $this->day('event_date'),
            'event_time' => $this->nullableText('event_time'),
            'description' => $this->nullableText('description'),
            'color' => EventColor::from((string) $this->validated('color')),
            'icon' => EventIcon::from((string) $this->validated('icon')),
        ];
    }
}
