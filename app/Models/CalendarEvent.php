<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\EventColor;
use App\Enums\EventIcon;
use App\Enums\RecurrenceFrequency;
use Carbon\CarbonImmutable;
use Database\Factories\CalendarEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An entry in the household calendar, shared by every member. With a
 * recurrence frequency it is a series starting on event_date; its occurrences
 * are computed on demand by App\Services\CalendarService.
 *
 * @property int $id
 * @property int $household_id
 * @property string $title
 * @property CarbonImmutable $event_date
 * @property string|null $event_time
 * @property string|null $description
 * @property EventColor $color
 * @property EventIcon $icon
 * @property RecurrenceFrequency|null $recurrence_frequency
 * @property int $recurrence_interval
 * @property string|null $recurrence_weekdays
 * @property CarbonImmutable|null $recurrence_until
 * @property int $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['household_id', 'title', 'event_date', 'event_time', 'description', 'color', 'icon', 'recurrence_frequency', 'recurrence_interval', 'recurrence_weekdays', 'recurrence_until', 'created_by_user_id', 'updated_by_user_id'])]
class CalendarEvent extends Model
{
    /** @use HasFactory<CalendarEventFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_date' => DateOnly::class,
            'color' => EventColor::class,
            'icon' => EventIcon::class,
            'recurrence_frequency' => RecurrenceFrequency::class,
            'recurrence_interval' => 'integer',
            'recurrence_until' => DateOnly::class,
        ];
    }

    /**
     * @return BelongsTo<Household, $this>
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Occurrences of a recurring event that were changed or deleted alone.
     *
     * @return HasMany<CalendarEventException, $this>
     */
    public function exceptions(): HasMany
    {
        return $this->hasMany(CalendarEventException::class);
    }

    public function isRecurring(): bool
    {
        return $this->recurrence_frequency !== null;
    }

    /**
     * Weekdays (0 = Sunday … 6 = Saturday) a weekly series happens on,
     * defaulting to the weekday of its first date.
     *
     * @return list<int>
     */
    public function weekdays(): array
    {
        $days = array_map(intval(...), array_filter(explode(',', (string) $this->recurrence_weekdays), is_numeric(...)));
        sort($days);

        return $days === [] ? [$this->event_date->dayOfWeek] : array_values(array_unique($days));
    }

    /**
     * Single (non-recurring) events of a household between two dates, in chronological order with
     * all-day events (no time) first on each day.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSingleBetween(Builder $query, Household $household, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $query
            ->where('household_id', $household->id)
            ->whereNull('recurrence_frequency')
            ->whereBetween('event_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('event_date')
            ->orderBy('event_time')
            ->orderBy('id');
    }

    /**
     * The time as "HH:MM": MySQL returns seconds, SQLite returns what was stored.
     */
    public function time(): ?string
    {
        return $this->event_time === null ? null : substr($this->event_time, 0, 5);
    }

    /**
     * The page payload of the event, or of one of its occurrences: the
     * original date identifies the occurrence, and an exception overrides
     * its values.
     *
     * @return array<string, mixed>
     */
    public function toPage(?CarbonImmutable $occurrence = null, ?CalendarEventException $exception = null): array
    {
        $source = $exception ?? $this;

        return [
            'id' => $this->id,
            'occurrence' => $this->isRecurring() ? ($occurrence ?? $this->event_date)->toDateString() : null,
            'title' => $source->title,
            'date' => ($exception->event_date ?? $occurrence ?? $this->event_date)->toDateString(),
            'time' => $source->event_time === null ? null : substr($source->event_time, 0, 5),
            'description' => $source->description,
            'color' => $source->color?->value,
            'icon' => $source->icon?->value,
            'modified' => $exception !== null,
            'recurrence' => $this->isRecurring() ? [
                'frequency' => $this->recurrence_frequency?->value,
                'interval' => $this->recurrence_interval,
                'weekdays' => $this->weekdays(),
                'until' => $this->recurrence_until?->toDateString(),
            ] : null,
            'series' => $this->isRecurring() ? [
                'title' => $this->title,
                'date' => $this->event_date->toDateString(),
                'time' => $this->time(),
                'description' => $this->description,
                'color' => $this->color->value,
                'icon' => $this->icon->value,
            ] : null,
            'show_id' => null,
            'created_by' => $this->creator?->firstName(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
