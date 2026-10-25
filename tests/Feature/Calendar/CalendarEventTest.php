<?php

namespace Tests\Feature\Calendar;

use App\Enums\EventColor;
use App\Enums\EventIcon;
use App\Models\CalendarEvent;
use App\Models\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class CalendarEventTest extends TestCase
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

    private function event(string $date, ?string $time = null, ?User $creator = null, array $attributes = []): CalendarEvent
    {
        $creator ??= $this->bruno;

        return CalendarEvent::factory()->create([
            'household_id' => $creator->householdId(),
            'created_by_user_id' => $creator->id,
            'event_date' => $date,
            'event_time' => $time,
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'Aniversário da Letícia',
            'event_date' => '2026-10-25',
            'event_time' => '19:30',
            'description' => 'Jantar em casa',
            'color' => 'pink',
            'icon' => 'cake',
            ...$overrides,
        ];
    }

    public function test_events_are_created_for_the_users_household(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('calendar.events.store'), $this->payload(['household_id' => 999]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $event = CalendarEvent::query()->sole();
        $this->assertSame($this->household->id, $event->household_id);
        $this->assertSame($this->bruno->id, $event->created_by_user_id);
        $this->assertSame('2026-10-25', $event->event_date->toDateString());
        $this->assertSame('19:30', $event->time());
        $this->assertSame(EventColor::Pink, $event->color);
        $this->assertSame(EventIcon::Cake, $event->icon);
    }

    public function test_time_and_description_are_optional(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('calendar.events.store'), $this->payload(['event_time' => '', 'description' => '  ']))
            ->assertSessionHasNoErrors();

        $event = CalendarEvent::query()->sole();
        $this->assertNull($event->event_time);
        $this->assertNull($event->description);
    }

    public function test_invalid_events_are_rejected(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('calendar.events.store'), [
                'title' => '',
                'event_date' => '25/10/2026',
                'event_time' => '25:00',
                'color' => 'gold',
                'icon' => 'rocket',
            ])
            ->assertSessionHasErrors(['title', 'event_date', 'event_time', 'color', 'icon']);

        $this->assertDatabaseCount('calendar_events', 0);
    }

    public function test_the_page_lists_the_month_grid_in_chronological_order(): void
    {
        $this->event('2026-10-10', '18:00', attributes: ['title' => 'Jantar']);
        $this->event('2026-10-10', null, attributes: ['title' => 'Feriado']);
        $this->event('2026-10-10', '08:30', $this->leticia, ['title' => 'Consulta']);
        // October 2026 starts on Thursday and ends on Saturday: the grid runs 27/09–31/10.
        $this->event('2026-09-27', attributes: ['title' => 'Início da grade']);
        $this->event('2026-09-26', attributes: ['title' => 'Fora da grade']);
        $this->event('2026-11-01', attributes: ['title' => 'Novembro']);

        $this->actingAs($this->leticia)
            ->get(route('calendar.index', ['month' => '2026-10']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('calendar/index')
                ->where('filters.month', '2026-10')
                ->where('filters.view', 'calendar')
                ->has('events', 4)
                ->where('events.0.title', 'Início da grade')
                ->where('events.1.title', 'Feriado')
                ->where('events.1.time', null)
                ->where('events.2.title', 'Consulta')
                ->where('events.2.time', '08:30')
                ->where('events.2.created_by', 'Letícia')
                ->where('events.3.title', 'Jantar')
            );
    }

    public function test_the_view_and_month_filters_fall_back_to_defaults(): void
    {
        $this->actingAs($this->bruno)
            ->get(route('calendar.index', ['month' => '2026-13', 'view' => 'list']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.month', '2026-10')
                ->where('filters.view', 'list')
            );

        $this->actingAs($this->bruno)
            ->get(route('calendar.index', ['view' => 'agenda']))
            ->assertInertia(fn (Assert $page) => $page->where('filters.view', 'calendar'));
    }

    public function test_every_member_can_edit_and_delete_household_events(): void
    {
        $event = $this->event('2026-10-10');

        $this->actingAs($this->leticia)
            ->put(route('calendar.events.update', $event), $this->payload(['event_time' => '']))
            ->assertSessionHasNoErrors();

        $event->refresh();
        $this->assertSame('Aniversário da Letícia', $event->title);
        $this->assertNull($event->event_time);
        $this->assertSame($this->bruno->id, $event->created_by_user_id);
        $this->assertSame($this->leticia->id, $event->updated_by_user_id);

        $this->actingAs($this->leticia)
            ->delete(route('calendar.events.destroy', $event))
            ->assertRedirect();

        $this->assertModelMissing($event);
    }

    public function test_events_are_isolated_between_households(): void
    {
        $event = $this->event('2026-10-10');
        $outsider = $this->createOutsider();
        $this->event('2026-10-11', creator: $outsider, attributes: ['title' => 'Outra casa']);

        $this->actingAs($outsider)
            ->get(route('calendar.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('events', 1)
                ->where('events.0.title', 'Outra casa')
            );

        // Authorization happens before validation, so even an invalid payload is a 404.
        $this->actingAs($outsider)->put(route('calendar.events.update', $event), [])->assertNotFound();
        $this->actingAs($outsider)->delete(route('calendar.events.destroy', $event))->assertNotFound();

        $this->assertModelExists($event);
    }

    public function test_the_dashboard_lists_upcoming_events(): void
    {
        $this->event('2026-10-20', attributes: ['title' => 'Ontem']);
        $this->event('2026-10-21', '20:00', attributes: ['title' => 'Hoje']);
        $this->event('2026-11-20', attributes: ['title' => 'Daqui a 30 dias']);
        $this->event('2026-11-21', attributes: ['title' => 'Depois']);

        $this->actingAs($this->bruno)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('events', 2)
                ->where('events.0.title', 'Hoje')
                ->where('events.0.time', '20:00')
                ->where('events.1.title', 'Daqui a 30 dias')
            );
    }

    public function test_household_shows_are_listed_on_their_dates(): void
    {
        $this->event('2026-10-25', '19:30', attributes: ['title' => 'Jantar']);
        Show::factory()->create([
            'household_id' => $this->household->id,
            'band' => 'Iron Maiden',
            'tour' => 'Run For Your Lives',
            'venue' => 'Allianz Parque, São Paulo',
            'show_date' => '2026-10-25',
        ]);
        Show::factory()->create(['household_id' => $this->household->id, 'show_date' => '2026-11-01']);
        Show::factory()->create(['household_id' => $this->createOutsider()->householdId(), 'show_date' => '2026-10-25']);

        $this->actingAs($this->leticia)
            ->get(route('calendar.index', ['month' => '2026-10']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('events', 2)
                ->where('events.0.title', 'Iron Maiden')
                ->where('events.0.date', '2026-10-25')
                ->where('events.0.time', null)
                ->where('events.0.description', 'Run For Your Lives · Allianz Parque, São Paulo')
                ->where('events.0.icon', 'music')
                ->where('events.0.show_id', Show::query()->where('band', 'Iron Maiden')->value('id'))
                ->where('events.1.title', 'Jantar')
                ->where('events.1.show_id', null)
            );

        $this->actingAs($this->bruno)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('events', 3)
                ->where('events.0.title', 'Iron Maiden')
                ->where('events.1.title', 'Jantar')
                ->where('events.2.date', '2026-11-01')
            );
    }

    public function test_guests_cannot_access_the_calendar(): void
    {
        $this->get(route('calendar.index'))->assertRedirect(route('login'));
        $this->post(route('calendar.events.store'), $this->payload())->assertRedirect(route('login'));
    }
}
