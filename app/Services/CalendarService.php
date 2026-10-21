<?php

namespace App\Services;

use App\Enums\RecurrenceFrequency;
use App\Models\CalendarEvent;
use App\Models\CalendarEventException;
use App\Models\Household;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lists the household agenda and edits events, expanding recurring series
 * into occurrences on demand. Nothing is materialized: a series stores its
 * rule, and only occurrences changed or deleted on their own are stored, as
 * exceptions keyed by their original date.
 */
final class CalendarService
{
    /**
     * Fields of the rule that decide which dates a series happens on.
     */
    private const RULE_FIELDS = ['event_date', 'recurrence_frequency', 'recurrence_interval', 'recurrence_weekdays'];

    /**
     * Every occurrence between two dates (inclusive) as page payloads, in
     * chronological order with all-day events first on each day.
     *
     * @return list<array<string, mixed>>
     */
    public function between(Household $household, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $items = CalendarEvent::query()
            ->singleBetween($household, $from, $to)
            ->with('creator:id,name')
            ->get()
            ->map(fn (CalendarEvent $event) => $event->toPage())
            ->all();

        $series = CalendarEvent::query()
            ->where('household_id', $household->id)
            ->whereNotNull('recurrence_frequency')
            ->where('event_date', '<=', $to->toDateString())
            ->where(fn ($query) => $query
                ->whereNull('recurrence_until')
                ->orWhere('recurrence_until', '>=', $from->toDateString()))
            ->with(['creator:id,name', 'exceptions' => fn ($query) => $query
                ->whereBetween('original_date', [$from->toDateString(), $to->toDateString()])])
            ->get();

        foreach ($series as $event) {
            $exceptions = $event->exceptions->keyBy(fn (CalendarEventException $exception) => $exception->original_date->toDateString());

            foreach ($this->datesBetween($event, $from, $to) as $date) {
                $exception = $exceptions->get($date->toDateString());

                if ($exception?->cancelled) {
                    continue;
                }

                // A changed occurrence may have been moved out of the range.
                if ($exception !== null && ! $exception->event_date?->betweenIncluded($from, $to)) {
                    continue;
                }

                $items[] = $event->toPage($date, $exception);
            }
        }

        // Occurrences moved into the range from an original date outside it.
        $movedIn = CalendarEventException::query()
            ->where('cancelled', false)
            ->whereBetween('event_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotBetween('original_date', [$from->toDateString(), $to->toDateString()])
            ->whereHas('event', fn ($query) => $query->where('household_id', $household->id))
            ->with('event.creator:id,name')
            ->get();

        foreach ($movedIn as $exception) {
            if ($this->occursOn($exception->event, $exception->original_date)) {
                $items[] = $exception->event->toPage($exception->original_date, $exception);
            }
        }

        usort($items, fn (array $a, array $b) => [$a['date'], $a['time'] ?? '', $a['id'], $a['occurrence'] ?? '']
            <=> [$b['date'], $b['time'] ?? '', $b['id'], $b['occurrence'] ?? '']);

        return $items;
    }

    /**
     * The original dates a series happens on between two dates (inclusive).
     * A single event happens only on its own date.
     *
     * @return list<CarbonImmutable>
     */
    public function datesBetween(CalendarEvent $event, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Day arithmetic runs in UTC so midnights skipped by old daylight
        // saving changes never shift a date.
        $start = $this->utc($event->event_date);
        $first = $this->utc($from)->max($start);
        $end = $this->utc($to);

        if ($event->recurrence_until !== null) {
            $end = $end->min($this->utc($event->recurrence_until));
        }

        if ($first->gt($end)) {
            return [];
        }

        $interval = max(1, $event->recurrence_interval);

        $dates = match ($event->recurrence_frequency) {
            null => [$start],
            RecurrenceFrequency::Daily => $this->daily($start, $first, $end, $interval),
            RecurrenceFrequency::Weekly => $this->weekly($start, $first, $end, $interval, $event->weekdays()),
            RecurrenceFrequency::Monthly => $this->monthly($start, $first, $end, $interval),
            RecurrenceFrequency::Yearly => $this->monthly($start, $first, $end, $interval * 12),
        };

        $local = [];

        foreach ($dates as $date) {
            if ($date->betweenIncluded($first, $end)) {
                $local[] = CarbonImmutable::createFromFormat('!Y-m-d', $date->toDateString(), config('app.timezone'));
            }
        }

        return $local;
    }

    public function occursOn(CalendarEvent $event, CarbonImmutable $date): bool
    {
        return $this->datesBetween($event, $date, $date) !== [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Household $household, User $actor, array $data): CalendarEvent
    {
        return CalendarEvent::create([
            ...$data,
            'household_id' => $household->id,
            'created_by_user_id' => $actor->id,
        ]);
    }

    /**
     * Edit a single event or a whole series. Changing which dates a series
     * happens on discards its changed and deleted occurrences, since their
     * original dates may no longer exist; shortening it only drops the
     * exceptions past its new end.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateSeries(CalendarEvent $event, User $actor, array $data): void
    {
        DB::transaction(function () use ($event, $actor, $data) {
            $event->fill([...$data, 'updated_by_user_id' => $actor->id]);

            if (! $event->isRecurring() || $event->isDirty(self::RULE_FIELDS)) {
                $event->exceptions()->delete();
            } elseif ($event->recurrence_until !== null) {
                $event->exceptions()->where('original_date', '>', $event->recurrence_until->toDateString())->delete();
            }

            $event->save();
        });
    }

    /**
     * Change one occurrence of a series, keeping the rest as they are.
     *
     * @param  array{title: string, event_date: CarbonImmutable, event_time: ?string, description: ?string, color: mixed, icon: mixed}  $data
     */
    public function updateOccurrence(CalendarEvent $event, CarbonImmutable $occurrence, User $actor, array $data): void
    {
        $this->ensureOccurrence($event, $occurrence);

        CalendarEventException::updateOrCreate(
            ['calendar_event_id' => $event->id, 'original_date' => $occurrence->toDateString()],
            [
                'cancelled' => false,
                'title' => $data['title'],
                'event_date' => $data['event_date'],
                'event_time' => $data['event_time'],
                'description' => $data['description'],
                'color' => $data['color'],
                'icon' => $data['icon'],
                'updated_by_user_id' => $actor->id,
            ],
        );
    }

    /**
     * Delete one occurrence of a series, keeping the rest.
     */
    public function cancelOccurrence(CalendarEvent $event, CarbonImmutable $occurrence, User $actor): void
    {
        $this->ensureOccurrence($event, $occurrence);

        CalendarEventException::updateOrCreate(
            ['calendar_event_id' => $event->id, 'original_date' => $occurrence->toDateString()],
            [
                'cancelled' => true,
                'title' => null,
                'event_date' => null,
                'event_time' => null,
                'description' => null,
                'color' => null,
                'icon' => null,
                'updated_by_user_id' => $actor->id,
            ],
        );
    }

    private function ensureOccurrence(CalendarEvent $event, CarbonImmutable $occurrence): void
    {
        if (! $event->isRecurring() || ! $this->occursOn($event, $occurrence)) {
            throw ValidationException::withMessages([
                'occurrence' => 'Esta data não faz parte da série do evento.',
            ]);
        }
    }

    private function utc(CarbonImmutable $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date->toDateString(), 'UTC');
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function daily(CarbonImmutable $start, CarbonImmutable $first, CarbonImmutable $end, int $interval): array
    {
        $skipped = intdiv((int) $start->diffInDays($first) + $interval - 1, $interval);
        $dates = [];

        for ($day = $start->addDays($skipped * $interval); $day->lte($end); $day = $day->addDays($interval)) {
            $dates[] = $day;
        }

        return $dates;
    }

    /**
     * @param  list<int>  $weekdays
     * @return list<CarbonImmutable>
     */
    private function weekly(CarbonImmutable $start, CarbonImmutable $first, CarbonImmutable $end, int $interval, array $weekdays): array
    {
        $anchor = $start->startOfWeek(CarbonInterface::SUNDAY);
        $weeks = intdiv((int) $anchor->diffInDays($first->startOfWeek(CarbonInterface::SUNDAY)), 7);
        $dates = [];

        for ($week = $anchor->addWeeks(intdiv($weeks, $interval) * $interval); $week->lte($end); $week = $week->addWeeks($interval)) {
            foreach ($weekdays as $weekday) {
                $day = $week->addDays($weekday);

                if ($day->gte($start)) {
                    $dates[] = $day;
                }
            }
        }

        return $dates;
    }

    /**
     * Same day of the month every interval months; in shorter months the
     * occurrence falls on the last day (e.g. 31 → 30/04, 29/02 → 28/02).
     *
     * @return list<CarbonImmutable>
     */
    private function monthly(CarbonImmutable $start, CarbonImmutable $first, CarbonImmutable $end, int $interval): array
    {
        $months = ($first->year - $start->year) * 12 + $first->month - $start->month;
        $dates = [];

        for ($offset = intdiv($months, $interval) * $interval; ; $offset += $interval) {
            $month = $start->startOfMonth()->addMonthsNoOverflow($offset);

            if ($month->gt($end)) {
                break;
            }

            $dates[] = $month->setDay(min($start->day, $month->daysInMonth));
        }

        return $dates;
    }
}
