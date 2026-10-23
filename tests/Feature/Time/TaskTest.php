<?php

namespace Tests\Feature\Time;

use App\Enums\TaskGoalType;
use App\Enums\TimerStatus;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\TimerSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class TaskTest extends TestCase
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

    public function test_tasks_are_created_for_the_signed_in_user(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('time.tasks.store'), [
                'title' => 'Aprender Rust',
                'goal_type' => 'time',
                'target_minutes' => 1200,
                'outcome' => 'ignorado sem meta de resultado',
                'deadline' => '2026-12-31',
                'user_id' => $this->leticia->id,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $task = Task::query()->sole();
        $this->assertSame($this->bruno->id, $task->user_id);
        $this->assertSame(TaskGoalType::Time, $task->goal_type);
        $this->assertSame(1200, $task->target_minutes);
        $this->assertNull($task->outcome);
        $this->assertSame('2026-12-31', $task->deadline?->toDateString());
    }

    public function test_time_goals_require_a_target_and_invalid_tasks_are_rejected(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('time.tasks.store'), ['title' => '', 'goal_type' => 'time', 'deadline' => '31/12/2026'])
            ->assertSessionHasErrors(['title', 'target_minutes', 'deadline']);

        $this->actingAs($this->bruno)
            ->post(route('time.tasks.store'), ['title' => 'X', 'goal_type' => 'time', 'target_minutes' => -5])
            ->assertSessionHasErrors(['target_minutes']);

        $this->actingAs($this->bruno)
            ->post(route('time.tasks.store'), ['title' => 'X', 'goal_type' => 'forever'])
            ->assertSessionHasErrors(['goal_type']);

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_tasks_can_be_edited_completed_reopened_archived_and_restored(): void
    {
        $task = Task::factory()->for($this->bruno)->create(['title' => 'Antigo']);

        $this->actingAs($this->bruno)
            ->put(route('time.tasks.update', $task), ['title' => 'Novo', 'goal_type' => 'outcome', 'outcome' => 'Terminar o curso'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Novo', $task->refresh()->title);
        $this->assertSame('Terminar o curso', $task->outcome);

        $this->post(route('time.tasks.complete', $task));
        $this->assertSame('completed', $task->refresh()->status()->value);

        $this->delete(route('time.tasks.reopen', $task));
        $this->assertSame('active', $task->refresh()->status()->value);

        $this->post(route('time.tasks.archive', $task));
        $this->assertSame('archived', $task->refresh()->status()->value);

        $this->delete(route('time.tasks.restore', $task));
        $this->assertSame('active', $task->refresh()->status()->value);
    }

    public function test_renaming_and_archiving_keep_the_history(): void
    {
        $task = Task::factory()->for($this->bruno)->create(['title' => 'Gamidas']);
        $entry = TimeEntry::factory()->create(['task_id' => $task->id, 'duration_minutes' => 90]);

        $this->actingAs($this->bruno)->put(route('time.tasks.update', $task), ['title' => 'Gamidas app', 'goal_type' => 'none']);
        $this->post(route('time.tasks.archive', $task));

        $this->assertDatabaseHas('time_entries', ['id' => $entry->id, 'task_id' => $task->id, 'duration_minutes' => 90]);

        $this->get(route('time.reports.index', ['from' => '2026-10-01', 'to' => '2026-10-31']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.total_minutes', 90)
                ->where('by_task.0.title', 'Gamidas app')
                ->where('by_task.0.status', 'archived'));
    }

    public function test_archiving_stops_the_open_timer_of_the_task(): void
    {
        $task = Task::factory()->for($this->bruno)->create();

        $this->actingAs($this->bruno)->post(route('time.timer.start'), ['task_id' => $task->id]);
        $this->post(route('time.tasks.archive', $task))->assertSessionHasNoErrors();

        $this->assertSame(TimerStatus::Stopped, TimerSession::query()->sole()->status);
        $this->assertDatabaseCount('time_entries', 0);
    }

    public function test_tasks_without_history_can_be_deleted(): void
    {
        $task = Task::factory()->for($this->bruno)->create();

        $this->actingAs($this->bruno)->delete(route('time.tasks.destroy', $task))->assertSessionHasNoErrors();

        $this->assertModelMissing($task);
    }

    public function test_tasks_with_history_cannot_be_deleted(): void
    {
        $withEntry = Task::factory()->for($this->bruno)->create();
        TimeEntry::factory()->create(['task_id' => $withEntry->id]);
        $withSession = Task::factory()->for($this->bruno)->create();
        TimerSession::query()->create([
            'user_id' => $this->bruno->id,
            'task_id' => $withSession->id,
            'status' => TimerStatus::Stopped,
            'started_at' => Date::now()->subHour(),
            'ended_at' => Date::now(),
            'accumulated_seconds' => 3600,
        ]);

        $this->actingAs($this->bruno)->delete(route('time.tasks.destroy', $withEntry))->assertSessionHasErrors('task');
        $this->delete(route('time.tasks.destroy', $withSession))->assertSessionHasErrors('task');

        $this->assertModelExists($withEntry);
        $this->assertModelExists($withSession);
    }

    public function test_tasks_of_other_users_are_not_found(): void
    {
        $task = Task::factory()->for($this->leticia)->create(['title' => 'Privada']);

        $this->actingAs($this->bruno);
        $this->put(route('time.tasks.update', $task), ['title' => 'Hack', 'goal_type' => 'none'])->assertNotFound();
        $this->post(route('time.tasks.complete', $task))->assertNotFound();
        $this->post(route('time.tasks.archive', $task))->assertNotFound();
        $this->delete(route('time.tasks.destroy', $task))->assertNotFound();

        $this->get(route('time.tasks.index'))
            ->assertInertia(fn (Assert $page) => $page->component('time/tasks')->has('tasks', 0));

        $this->assertSame('Privada', $task->refresh()->title);
        $this->assertNull($task->completed_at);
    }

    public function test_the_task_list_shows_goal_progress_from_manual_entries_only(): void
    {
        $task = Task::factory()->for($this->bruno)->timeGoal(20)->create(['deadline' => '2026-10-31']);
        TimeEntry::factory()->create(['task_id' => $task->id, 'duration_minutes' => 300]);
        TimeEntry::factory()->create(['task_id' => $task->id, 'duration_minutes' => 60, 'entry_date' => '2026-09-01']);
        TimerSession::query()->create([
            'user_id' => $this->bruno->id,
            'task_id' => $task->id,
            'status' => TimerStatus::Stopped,
            'started_at' => Date::now()->subHours(10),
            'ended_at' => Date::now(),
            'accumulated_seconds' => 36_000,
        ]);

        $this->actingAs($this->bruno)
            ->get(route('time.tasks.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.active', 1)
                ->where('tasks.0.has_history', true)
                ->where('tasks.0.progress.recorded_minutes', 360)
                ->where('tasks.0.progress.percent', 30)
                ->where('tasks.0.progress.remaining_minutes', 840)
                ->where('tasks.0.progress.days_left', 10)
                ->where('tasks.0.progress.overdue', false));
    }

    public function test_outcome_goals_are_reached_by_completing_the_task(): void
    {
        $task = Task::factory()->for($this->bruno)->create([
            'goal_type' => TaskGoalType::TimeAndOutcome,
            'target_minutes' => 600,
            'outcome' => 'Publicar o projeto',
            'deadline' => '2026-10-01',
        ]);
        TimeEntry::factory()->create(['task_id' => $task->id, 'duration_minutes' => 600]);

        $this->actingAs($this->bruno)
            ->get(route('time.tasks.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tasks.0.progress.percent', 100)
                ->where('tasks.0.progress.remaining_minutes', 0)
                ->where('tasks.0.progress.outcome_done', false)
                ->where('tasks.0.progress.overdue', true));

        $this->post(route('time.tasks.complete', $task));

        $this->get(route('time.tasks.index', ['status' => 'completed']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tasks.0.progress.outcome_done', true)
                ->where('tasks.0.progress.overdue', false));
    }
}
