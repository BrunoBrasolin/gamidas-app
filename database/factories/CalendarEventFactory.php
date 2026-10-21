<?php

namespace Database\Factories;

use App\Enums\EventColor;
use App\Enums\EventIcon;
use App\Enums\RecurrenceFrequency;
use App\Models\CalendarEvent;
use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * @extends Factory<CalendarEvent>
 */
class CalendarEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'title' => fake()->words(2, true),
            'event_date' => Date::today(),
            'event_time' => null,
            'color' => EventColor::Blue,
            'icon' => EventIcon::Calendar,
            'created_by_user_id' => User::factory(),
        ];
    }

    /**
     * A series repeating every interval units of the frequency.
     */
    public function recurring(RecurrenceFrequency $frequency = RecurrenceFrequency::Weekly, int $interval = 1, ?string $until = null): static
    {
        return $this->state([
            'recurrence_frequency' => $frequency,
            'recurrence_interval' => $interval,
            'recurrence_until' => $until,
        ]);
    }
}
