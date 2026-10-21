<?php

namespace Tests\Feature\Calendar;

use App\Enums\RecurrenceFrequency;
use App\Models\CalendarEvent;
use App\Models\CalendarEventException;
use App\Services\CalendarService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class CalendarRecurrenceTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-10-21 10:00:00');
        $this->createHousehold();
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    private function series(string $date, RecurrenceFrequency $frequency = RecurrenceFrequency::Weekly, int $interval = 1, ?string $until = null, array $attributes = []): CalendarEvent
    {
        return CalendarEvent::factory()->recurring($frequency, $interval, $until)->create([
            'household_id' => $this->household->id,
            'created_by_user_id' => $this->bruno->id,
            'event_date' => $date,
            ...$attributes,
        ]);
    }

    /**
     * @return list<string>
     */
    private function dates(CalendarEvent $event, string $from, string $to): array
    {
        return array_map(
            fn (CarbonImmutable $date) => $date->toDateString(),
            app(CalendarService::class)->datesBetween($event, CarbonImmutable::parse($from), CarbonImmutable::parse($to)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'Futebol',
            'event_date' => '2026-10-07',
            'event_time' => '22:00',
            'description' => null,
            'color' => 'green',
            'icon' => 'sport',
            'recurrence_frequency' => 'weekly',
            'recurrence_interval' => 1,
            ...$overrides,
        ];
    }

    /**
     * @return list<string>
     */
    private function pageDates(string $month, string $title): array
    {
        $dates = [];

        $this->actingAs($this->bruno)
            ->get(route('calendar.index', ['month' => $month]))
            ->assertInertia(function (Assert $page) use (&$dates, $title) {
                foreach ($page->toArray()['props']['events'] as $event) {
                    if ($event['title'] === $title) {
                        $dates[] = $event['date'];
                    }
                }
            });

        return $dates;
    }

    public function test_a_weekly_event_is_shown_on_every_week_of_the_grid(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('calendar.events.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $event = CalendarEvent::query()->sole();
        $this->assertSame(RecurrenceFrequency::Weekly, $event->recurrence_frequency);
        $this->assertNull($event->recurrence_weekdays);
        $this->assertNull($event->recurrence_until);

        // Every Wednesday from the first date (07/10) until the end of the grid (31/10).
        $this->actingAs($this->bruno)
            ->get(route('calendar.index', ['month' => '2026-10']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('events', 4)
                ->where('events.0.date', '2026-10-07')
                ->where('events.0.occurrence', '2026-10-07')
                ->where('events.0.time', '22:00')
                ->where('events.0.recurrence.frequency', 'weekly')
                ->where('events.0.recurrence.weekdays', [3])
                ->where('events.0.series.date', '2026-10-07')
                ->where('events.3.date', '2026-10-28')
                ->where('events.3.modified', false)
            );
    }

    public function test_daily_events_respect_the_interval_and_end_date(): void
    {
        $event = $this->series('2026-10-01', RecurrenceFrequency::Daily, 3, '2026-10-13');

        $this->assertSame(['2026-10-01', '2026-10-04', '2026-10-07', '2026-10-10', '2026-10-13'], $this->dates($event, '2026-09-01', '2026-12-31'));
        $this->assertSame(['2026-10-07', '2026-10-10'], $this->dates($event, '2026-10-05', '2026-10-11'));
        $this->assertSame([], $this->dates($event, '2026-10-14', '2026-10-31'));
    }

    public function test_custom_weekly_events_happen_on_the_chosen_weekdays(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('calendar.events.store'), $this->payload([
                'event_date' => '2026-10-05',
                'recurrence_interval' => 2,
                'recurrence_weekdays' => [3, 1, 3],
            ]))
            ->assertSessionHasNoErrors();

        $event = CalendarEvent::query()->sole();
        $this->assertSame('1,3', $event->recurrence_weekdays);

        // Monday and Wednesday every other week, starting on Monday 05/10.
        $this->assertSame(
            ['2026-10-05', '2026-10-07', '2026-10-19', '2026-10-21', '2026-11-02', '2026-11-04'],
            $this->dates($event, '2026-10-01', '2026-11-08'),
        );
        $this->assertSame(['2026-11-16', '2026-11-18'], $this->dates($event, '2026-11-09', '2026-11-22'));
    }

    public function test_monthly_and_yearly_events_fall_on_the_last_day_of_shorter_months(): void
    {
        $monthly = $this->series('2026-10-31', RecurrenceFrequency::Monthly);
        $this->assertSame(['2026-10-31', '2026-11-30', '2026-12-31', '2027-01-31', '2027-02-28'], $this->dates($monthly, '2026-10-01', '2027-02-28'));

        $quarterly = $this->series('2026-01-15', RecurrenceFrequency::Monthly, 3);
        $this->assertSame(['2026-10-15', '2027-01-15'], $this->dates($quarterly, '2026-08-01', '2027-03-31'));

        $birthday = $this->series('2024-02-29', RecurrenceFrequency::Yearly);
        $this->assertSame(['2026-02-28', '2027-02-28', '2028-02-29'], $this->dates($birthday, '2026-01-01', '2028-12-31'));
    }

    public function test_the_recurrence_rule_is_validated(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('calendar.events.store'), $this->payload([
                'recurrence_frequency' => 'hourly',
                'recurrence_interval' => 0,
                'recurrence_weekdays' => [7],
                'recurrence_until' => '2026-10-01',
            ]))
            ->assertSessionHasErrors(['recurrence_frequency', 'recurrence_interval', 'recurrence_weekdays.0', 'recurrence_until']);

        $this->assertDatabaseCount('calendar_events', 0);
    }

    public function test_events_without_frequency_ignore_the_rest_of_the_rule(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('calendar.events.store'), $this->payload([
                'recurrence_frequency' => '',
                'recurrence_interval' => 5,
                'recurrence_weekdays' => [1],
                'recurrence_until' => '2026-12-31',
            ]))
            ->assertSessionHasNoErrors();

        $event = CalendarEvent::query()->sole();
        $this->assertFalse($event->isRecurring());
        $this->assertSame(1, $event->recurrence_interval);
        $this->assertNull($event->recurrence_weekdays);
        $this->assertNull($event->recurrence_until);
        $this->assertSame(['2026-10-07'], $this->pageDates('2026-10', 'Futebol'));
    }

    public function test_a_single_occurrence_can_be_changed_and_moved(): void
    {
        $event = $this->series('2026-10-07', attributes: ['title' => 'Futebol', 'event_time' => '22:00']);

        $this->actingAs($this->leticia)
            ->put(route('calendar.events.update', $event), $this->payload([
                'title' => 'Futebol (adiado)',
                'event_date' => '2026-11-02',
                'event_time' => '20:00',
                'scope' => 'occurrence',
                'occurrence' => '2026-10-14',
            ]))
            ->assertSessionHasNoErrors();

        $exception = CalendarEventException::query()->sole();
        $this->assertSame('2026-10-14', $exception->original_date->toDateString());
        $this->assertSame($this->leticia->id, $exception->updated_by_user_id);
        $this->assertSame('Futebol', $event->fresh()->title);

        $this->assertSame(['2026-10-07', '2026-10-21', '2026-10-28'], $this->pageDates('2026-10', 'Futebol'));
        $this->assertSame(['2026-11-04', '2026-11-11', '2026-11-18', '2026-11-25', '2026-12-02'], $this->pageDates('2026-11', 'Futebol'));

        // Moved into November's grid from an original date outside it.
        $this->actingAs($this->bruno)
            ->get(route('calendar.index', ['month' => '2026-11']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('events.0.title', 'Futebol (adiado)')
                ->where('events.0.date', '2026-11-02')
                ->where('events.0.time', '20:00')
                ->where('events.0.occurrence', '2026-10-14')
                ->where('events.0.modified', true)
                ->where('events.0.series.title', 'Futebol')
            );
    }

    public function test_a_single_occurrence_can_be_deleted(): void
    {
        $event = $this->series('2026-10-07', attributes: ['title' => 'Futebol']);

        $this->actingAs($this->bruno)
            ->delete(route('calendar.events.destroy', ['event' => $event, 'scope' => 'occurrence', 'occurrence' => '2026-10-21']))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertModelExists($event);
        $this->assertTrue(CalendarEventException::query()->sole()->cancelled);
        $this->assertSame(['2026-10-07', '2026-10-14', '2026-10-28'], $this->pageDates('2026-10', 'Futebol'));
    }

    public function test_occurrences_outside_the_series_are_rejected(): void
    {
        $event = $this->series('2026-10-07');

        $this->actingAs($this->bruno)
            ->put(route('calendar.events.update', $event), $this->payload(['scope' => 'occurrence', 'occurrence' => '2026-10-08']))
            ->assertSessionHasErrors('occurrence');

        $this->actingAs($this->bruno)
            ->delete(route('calendar.events.destroy', ['event' => $event, 'scope' => 'occurrence', 'occurrence' => '2026-09-30']))
            ->assertSessionHasErrors('occurrence');

        $this->assertDatabaseCount('calendar_event_exceptions', 0);
    }

    public function test_deleting_the_series_removes_every_occurrence(): void
    {
        $event = $this->series('2026-10-07');
        CalendarEventException::create(['calendar_event_id' => $event->id, 'original_date' => '2026-10-14', 'cancelled' => true]);

        $this->actingAs($this->bruno)
            ->delete(route('calendar.events.destroy', ['event' => $event, 'scope' => 'series']))
            ->assertRedirect();

        $this->assertModelMissing($event);
        $this->assertDatabaseCount('calendar_event_exceptions', 0);
    }

    public function test_editing_the_series_keeps_exceptions_unless_its_dates_change(): void
    {
        $event = $this->series('2026-10-07', attributes: ['title' => 'Futebol']);
        $this->actingAs($this->bruno)
            ->delete(route('calendar.events.destroy', ['event' => $event, 'scope' => 'occurrence', 'occurrence' => '2026-10-14']));
        $this->actingAs($this->bruno)
            ->delete(route('calendar.events.destroy', ['event' => $event, 'scope' => 'occurrence', 'occurrence' => '2026-10-28']));

        // Same dates, new title and an end date: only exceptions past the end go away.
        $this->actingAs($this->leticia)
            ->put(route('calendar.events.update', $event), $this->payload([
                'title' => 'Futebol society',
                'recurrence_until' => '2026-10-21',
                'scope' => 'series',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(['2026-10-14'], CalendarEventException::query()->pluck('original_date')->map->toDateString()->all());
        $this->assertSame(['2026-10-07', '2026-10-21'], $this->pageDates('2026-10', 'Futebol society'));

        // Moving the series to Thursdays discards them.
        $this->actingAs($this->leticia)
            ->put(route('calendar.events.update', $event), $this->payload(['event_date' => '2026-10-08', 'scope' => 'series']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('calendar_event_exceptions', 0);
        $this->assertSame(['2026-10-08', '2026-10-15', '2026-10-22', '2026-10-29'], $this->pageDates('2026-10', 'Futebol'));
        $this->assertSame($this->leticia->id, $event->fresh()->updated_by_user_id);
    }

    public function test_a_series_can_become_a_single_event(): void
    {
        $event = $this->series('2026-10-07');
        CalendarEventException::create(['calendar_event_id' => $event->id, 'original_date' => '2026-10-14', 'cancelled' => true]);

        $this->actingAs($this->bruno)
            ->put(route('calendar.events.update', $event), $this->payload(['recurrence_frequency' => '', 'scope' => 'series']))
            ->assertSessionHasNoErrors();

        $this->assertFalse($event->fresh()->isRecurring());
        $this->assertDatabaseCount('calendar_event_exceptions', 0);
        $this->assertSame(['2026-10-07'], $this->pageDates('2026-10', 'Futebol'));
    }

    public function test_the_dashboard_lists_upcoming_occurrences(): void
    {
        $this->series('2026-10-01', RecurrenceFrequency::Weekly, attributes: ['title' => 'Aula', 'event_time' => '19:00']);
        $this->series('2026-10-22', RecurrenceFrequency::Daily, attributes: ['title' => 'Remédio', 'event_time' => '08:00']);

        // Today's Thursday class is 22/10; the daily reminder fills the rest of the list.
        $this->actingAs($this->bruno)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('events', 6)
                ->where('events.0.title', 'Remédio')
                ->where('events.0.date', '2026-10-22')
                ->where('events.1.title', 'Aula')
                ->where('events.1.date', '2026-10-22')
                ->where('events.5.date', '2026-10-26')
            );
    }

    public function test_occurrences_of_other_households_cannot_be_changed(): void
    {
        $event = $this->series('2026-10-07');
        $outsider = $this->createOutsider();

        $this->actingAs($outsider)
            ->put(route('calendar.events.update', $event), $this->payload(['scope' => 'occurrence', 'occurrence' => '2026-10-14']))
            ->assertNotFound();
        $this->actingAs($outsider)
            ->delete(route('calendar.events.destroy', ['event' => $event, 'scope' => 'occurrence', 'occurrence' => '2026-10-14']))
            ->assertNotFound();

        $this->assertDatabaseCount('calendar_event_exceptions', 0);
    }
}
