<?php

namespace Tests\Feature\Time;

use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class TimeEntryTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-10-21 10:00:00');
        $this->createHousehold();
        $this->task = Task::factory()->for($this->bruno)->create(['title' => 'Gamidas']);
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'task_id' => $this->task->id,
            'entry_date' => '2026-10-21',
            'minutes' => 90,
            'description' => 'Planilha',
            ...$overrides,
        ];
    }

    public function test_entries_are_created_edited_and_deleted(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('time.entries.store'), $this->payload(['user_id' => $this->leticia->id]))
            ->assertSessionHasNoErrors();

        $entry = TimeEntry::query()->sole();
        $this->assertSame($this->bruno->id, $entry->user_id);
        $this->assertSame('2026-10-21', $entry->entry_date->toDateString());
        $this->assertSame(90, $entry->duration_minutes);

        $this->put(route('time.entries.update', $entry), $this->payload(['minutes' => 120, 'entry_date' => '2026-10-20', 'description' => '']))
            ->assertSessionHasNoErrors();
        $entry->refresh();
        $this->assertSame(120, $entry->duration_minutes);
        $this->assertSame('2026-10-20', $entry->entry_date->toDateString());
        $this->assertNull($entry->description);

        $this->delete(route('time.entries.destroy', $entry))->assertSessionHasNoErrors();
        $this->assertModelMissing($entry);
    }

    public function test_entries_can_be_recorded_for_past_and_future_dates(): void
    {
        $this->actingAs($this->bruno)->post(route('time.entries.store'), $this->payload(['entry_date' => '2025-01-15']))->assertSessionHasNoErrors();
        $this->post(route('time.entries.store'), $this->payload(['entry_date' => '2026-12-24']))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('time_entries', 2);
    }

    public function test_invalid_durations_and_dates_are_rejected(): void
    {
        $this->actingAs($this->bruno);

        foreach ([0, -30, 1441, 'abc', 1.5] as $minutes) {
            $this->post(route('time.entries.store'), $this->payload(['minutes' => $minutes]))->assertSessionHasErrors('minutes');
        }

        $this->post(route('time.entries.store'), $this->payload(['entry_date' => '2026-02-30']))->assertSessionHasErrors('entry_date');
        $this->post(route('time.entries.store'), $this->payload(['task_id' => null]))->assertSessionHasErrors('task_id');

        $this->assertDatabaseCount('time_entries', 0);
    }

    public function test_entries_cannot_use_another_users_task(): void
    {
        $theirs = Task::factory()->for($this->leticia)->create();

        $this->actingAs($this->bruno)
            ->post(route('time.entries.store'), $this->payload(['task_id' => $theirs->id]))
            ->assertSessionHasErrors('task_id');

        $this->put(route('time.sheet.cell'), ['task_id' => $theirs->id, 'entry_date' => '2026-10-21', 'minutes' => 30])
            ->assertSessionHasErrors('task_id');

        $this->assertDatabaseCount('time_entries', 0);
    }

    public function test_entries_of_other_users_are_not_found(): void
    {
        $theirs = TimeEntry::factory()->create([
            'task_id' => Task::factory()->for($this->leticia)->create()->id,
            'duration_minutes' => 30,
        ]);

        $this->actingAs($this->bruno);
        $this->put(route('time.entries.update', $theirs), $this->payload())->assertNotFound();
        $this->delete(route('time.entries.destroy', $theirs))->assertNotFound();

        $this->assertSame(30, $theirs->refresh()->duration_minutes);
    }

    public function test_archived_tasks_receive_no_new_time_but_old_entries_can_be_corrected(): void
    {
        $entry = TimeEntry::factory()->create(['task_id' => $this->task->id, 'duration_minutes' => 60]);
        $this->task->update(['archived_at' => Date::now()]);

        $this->actingAs($this->bruno)
            ->post(route('time.entries.store'), $this->payload())
            ->assertSessionHasErrors('task_id');

        $this->put(route('time.entries.update', $entry), $this->payload(['minutes' => 75]))->assertSessionHasNoErrors();
        $this->assertSame(75, $entry->refresh()->duration_minutes);

        $active = Task::factory()->for($this->bruno)->create();
        $other = TimeEntry::factory()->create(['task_id' => $active->id]);
        $this->put(route('time.entries.update', $other), $this->payload())->assertSessionHasErrors('task_id');
    }

    public function test_multiple_entries_for_the_same_task_and_day_are_summed(): void
    {
        $this->actingAs($this->bruno);
        $this->post(route('time.entries.store'), $this->payload(['minutes' => 60]));
        $this->post(route('time.entries.store'), $this->payload(['minutes' => 30, 'description' => 'Revisão']));

        $this->get(route('time.days.show', '2026-10-21'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('time/day')
                ->has('entries', 2)
                ->where('by_task.0.minutes', 90)
                ->where('total_minutes', 90));

        $this->get(route('time.sheet', ['date' => '2026-10-21']))
            ->assertInertia(fn (Assert $page) => $page
                ->where("sheet.cells.2026-10-21.{$this->task->id}.minutes", 90)
                ->has("sheet.cells.2026-10-21.{$this->task->id}.entries", 2));
    }

    public function test_inline_cell_edits_create_update_and_clear_a_single_entry(): void
    {
        $this->actingAs($this->bruno);
        $cell = ['task_id' => $this->task->id, 'entry_date' => '2026-10-21'];

        $this->put(route('time.sheet.cell'), [...$cell, 'minutes' => 45])->assertSessionHasNoErrors();
        $entry = TimeEntry::query()->sole();
        $this->assertSame(45, $entry->duration_minutes);

        $this->put(route('time.sheet.cell'), [...$cell, 'minutes' => 100])->assertSessionHasNoErrors();
        $this->assertSame(100, $entry->refresh()->duration_minutes);
        $this->assertDatabaseCount('time_entries', 1);

        $this->put(route('time.sheet.cell'), [...$cell, 'minutes' => 0])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('time_entries', 0);
    }

    public function test_cells_with_several_entries_are_not_overwritten_inline(): void
    {
        TimeEntry::factory()->count(2)->create(['task_id' => $this->task->id, 'entry_date' => '2026-10-21', 'duration_minutes' => 30]);

        $this->actingAs($this->bruno)
            ->put(route('time.sheet.cell'), ['task_id' => $this->task->id, 'entry_date' => '2026-10-21', 'minutes' => 10])
            ->assertSessionHasErrors('minutes');

        $this->assertSame(60, (int) TimeEntry::query()->sum('duration_minutes'));
    }

    public function test_copying_a_previous_day_does_not_duplicate_entries(): void
    {
        $archived = Task::factory()->for($this->bruno)->archived()->create();
        TimeEntry::factory()->create(['task_id' => $this->task->id, 'entry_date' => '2026-10-19', 'duration_minutes' => 60, 'description' => 'A']);
        TimeEntry::factory()->create(['task_id' => $this->task->id, 'entry_date' => '2026-10-19', 'duration_minutes' => 30]);
        TimeEntry::factory()->create(['task_id' => $archived->id, 'entry_date' => '2026-10-19', 'duration_minutes' => 15]);

        $this->actingAs($this->bruno)
            ->get(route('time.days.show', '2026-10-21'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('copy_source.date', '2026-10-19')
                ->where('copy_source.count', 2));

        $this->post(route('time.days.copy', ['date' => '2026-10-21', 'from' => '2026-10-19']))->assertSessionHasNoErrors();
        $this->post(route('time.days.copy', ['date' => '2026-10-21', 'from' => '2026-10-19']))->assertSessionHasNoErrors();

        $copied = TimeEntry::query()->where('entry_date', '2026-10-21')->orderBy('duration_minutes')->get();
        $this->assertSame([30, 60], $copied->pluck('duration_minutes')->all());
        $this->assertSame($this->bruno->id, $copied->first()?->user_id);

        $this->post(route('time.days.copy', ['date' => '2026-10-21', 'from' => '2026-10-22']))->assertSessionHasErrors('from');
    }

    public function test_dates_are_stored_as_local_calendar_days(): void
    {
        // 23:30 in São Paulo is already the next day in UTC.
        Date::setTestNow('2026-10-21 23:30:00');

        $this->actingAs($this->bruno)->post(route('time.entries.store'), $this->payload(['entry_date' => '2026-10-21']));

        $this->assertDatabaseHas('time_entries', ['entry_date' => '2026-10-21']);
        $this->get(route('time.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('today', '2026-10-21')
                ->where('today_minutes', 90));
    }

    public function test_invalid_day_routes_are_not_found(): void
    {
        $this->actingAs($this->bruno)->get('/time/days/2026-02-30')->assertNotFound();
        $this->get('/time/days/1999-12-31')->assertNotFound();
    }
}
