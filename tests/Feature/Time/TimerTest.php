<?php

namespace Tests\Feature\Time;

use App\Enums\TimerStatus;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\TimerSession;
use App\Services\TimerService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class TimerTest extends TestCase
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

    private function start(?Task $task = null, ?string $existing = null): TestResponse
    {
        return $this->actingAs($this->bruno)->post(route('time.timer.start'), array_filter([
            'task_id' => ($task ?? $this->task)->id,
            'existing' => $existing,
        ]));
    }

    private function latestSession(): TimerSession
    {
        return TimerSession::query()->latest('id')->firstOrFail();
    }

    public function test_start_pause_resume_and_stop_measure_the_elapsed_time(): void
    {
        $this->start()->assertSessionHasNoErrors();
        $session = $this->latestSession();
        $this->assertSame(TimerStatus::Running, $session->status);

        Date::setTestNow('2026-10-21 10:25:00');
        $this->post(route('time.timer.pause', $session))->assertSessionHasNoErrors();
        $this->assertSame(1500, $session->refresh()->accumulated_seconds);
        $this->assertSame(TimerStatus::Paused, $session->status);

        // Time while paused does not count.
        Date::setTestNow('2026-10-21 12:00:00');
        $this->assertSame(1500, $session->refresh()->elapsedSeconds());
        $this->post(route('time.timer.resume', $session))->assertSessionHasNoErrors();

        Date::setTestNow('2026-10-21 12:10:30');
        $this->assertSame(2130, $session->refresh()->elapsedSeconds());
        $this->post(route('time.timer.stop', $session))->assertSessionHasNoErrors();

        $session->refresh();
        $this->assertSame(TimerStatus::Stopped, $session->status);
        $this->assertSame(2130, $session->elapsedSeconds());
        $this->assertSame('2026-10-21 12:10:30', $session->ended_at?->format('Y-m-d H:i:s'));
        $this->assertNull($session->running_lock);
    }

    public function test_stopping_a_timer_never_creates_or_changes_time_entries(): void
    {
        $entry = TimeEntry::factory()->create(['task_id' => $this->task->id, 'duration_minutes' => 45]);

        $this->start();
        Date::setTestNow('2026-10-21 13:00:00');
        $this->post(route('time.timer.pause', $this->latestSession()));
        $this->post(route('time.timer.resume', $this->latestSession()));
        $this->post(route('time.timer.stop', $this->latestSession()));

        $this->assertDatabaseCount('time_entries', 1);
        $this->assertSame(45, $entry->refresh()->duration_minutes);

        $this->get(route('time.sheet', ['date' => '2026-10-21']))
            ->assertInertia(fn (Assert $page) => $page->where('sheet.total_minutes', 45));
    }

    public function test_the_timer_state_is_recovered_after_a_refresh(): void
    {
        $this->start();

        Date::setTestNow('2026-10-21 10:07:05');

        $this->get(route('time.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('timer.status', 'running')
                ->where('timer.task.title', 'Gamidas')
                ->where('timer.elapsed_seconds', 425));

        $this->get(route('time.timer.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('time/timer')
                ->where('open_sessions.0.elapsed_seconds', 425)
                ->where('measured.today_seconds', 425));
    }

    public function test_starting_twice_is_idempotent(): void
    {
        $this->start();
        $this->start()->assertSessionHasNoErrors();

        $this->assertSame(1, TimerSession::query()->count());
    }

    public function test_a_second_timer_requires_a_decision_about_the_running_one(): void
    {
        $other = Task::factory()->for($this->bruno)->create(['title' => 'Leitura']);
        $this->start();
        $first = $this->latestSession();

        $this->start($other)->assertSessionHasErrors('timer_conflict');
        $this->assertSame(1, TimerSession::query()->count());

        $this->start($other, 'pause')->assertSessionHasNoErrors();
        $this->assertSame(TimerStatus::Paused, $first->refresh()->status);
        $this->assertSame(TimerStatus::Running, $this->latestSession()->status);

        // Resuming the paused one also asks; stopping the running one is explicit.
        $this->post(route('time.timer.resume', $first))->assertSessionHasErrors('timer_conflict');
        $this->post(route('time.timer.resume', $first), ['existing' => 'stop'])->assertSessionHasNoErrors();

        $this->assertSame(TimerStatus::Running, $first->refresh()->status);
        $this->assertSame(1, TimerSession::query()->where('status', TimerStatus::Running)->count());
    }

    public function test_starting_a_task_with_a_paused_session_resumes_it(): void
    {
        $this->start();
        $session = $this->latestSession();
        Date::setTestNow('2026-10-21 10:10:00');
        $this->post(route('time.timer.pause', $session));

        Date::setTestNow('2026-10-21 11:00:00');
        $this->start();
        Date::setTestNow('2026-10-21 11:05:00');

        $this->assertSame(1, TimerSession::query()->count());
        $this->assertSame(900, $session->refresh()->elapsedSeconds());
    }

    public function test_the_database_allows_a_single_running_timer_per_user(): void
    {
        $this->start();

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('timer_sessions')->insert([
            'user_id' => $this->bruno->id,
            'task_id' => $this->task->id,
            'status' => 'running',
            'started_at' => '2026-10-21 13:00:00',
            'accumulated_seconds' => 0,
            'running_lock' => 1,
        ]);
    }

    public function test_a_concurrent_start_that_loses_the_race_is_reported_as_a_conflict(): void
    {
        $other = Task::factory()->for($this->bruno)->create();
        $service = app(TimerService::class);

        // Simulate a request that read "no running timer" before another one
        // inserted its session: the unique index rejects the second insert.
        DB::table('timer_sessions')->insert([
            'user_id' => $this->bruno->id,
            'task_id' => $other->id,
            'status' => 'paused',
            'started_at' => '2026-10-21 12:00:00',
            'accumulated_seconds' => 0,
            'running_lock' => 1,
        ]);

        try {
            $service->start($this->bruno, $this->task);
            $this->fail('Expected a conflict.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('timer', $exception->errors());
        }

        $this->assertSame(1, TimerSession::query()->count());
    }

    public function test_repeated_pause_and_stop_are_harmless(): void
    {
        $this->start();
        $session = $this->latestSession();
        Date::setTestNow('2026-10-21 10:01:00');

        $this->post(route('time.timer.stop', $session))->assertSessionHasNoErrors();
        Date::setTestNow('2026-10-21 10:30:00');
        $this->post(route('time.timer.stop', $session))->assertSessionHasNoErrors();
        $this->post(route('time.timer.pause', $session))->assertSessionHasNoErrors();

        $this->assertSame(60, $session->refresh()->elapsedSeconds());
        $this->post(route('time.timer.resume', $session))->assertSessionHasErrors('timer');
    }

    public function test_a_session_crossing_midnight_stays_one_session(): void
    {
        Date::setTestNow('2026-10-21 23:30:00');
        $this->start();
        Date::setTestNow('2026-10-22 01:15:00');
        $this->post(route('time.timer.stop', $this->latestSession()));

        $session = $this->latestSession();
        $this->assertSame(1, TimerSession::query()->count());
        $this->assertSame(6300, $session->elapsedSeconds());
        $this->assertSame('2026-10-21', $session->started_at->toDateString());
        $this->assertDatabaseCount('time_entries', 0);
    }

    public function test_instants_are_stored_in_utc(): void
    {
        $this->start();

        // 10:00 in São Paulo (UTC−3) is 13:00 UTC.
        $this->assertSame('2026-10-21 13:00:00', DB::table('timer_sessions')->value('started_at'));
        $this->assertSame('2026-10-21 10:00:00', $this->latestSession()->started_at->format('Y-m-d H:i:s'));
    }

    public function test_missing_or_future_timestamps_never_produce_negative_time(): void
    {
        $session = TimerSession::query()->create([
            'user_id' => $this->bruno->id,
            'task_id' => $this->task->id,
            'status' => TimerStatus::Running,
            'started_at' => Date::now(),
            'last_resumed_at' => null,
            'accumulated_seconds' => 120,
            'running_lock' => 1,
        ]);
        $this->assertSame(120, $session->elapsedSeconds());

        $session->update(['last_resumed_at' => Date::now()->addHour()]);
        $this->assertSame(120, $session->refresh()->elapsedSeconds());
    }

    public function test_archived_tasks_cannot_be_timed(): void
    {
        $this->task->update(['archived_at' => Date::now()]);

        $this->start()->assertSessionHasErrors('task_id');
        $this->assertDatabaseCount('timer_sessions', 0);
    }

    public function test_timers_of_other_users_are_not_found(): void
    {
        $task = Task::factory()->for($this->leticia)->create();
        $this->actingAs($this->leticia)->post(route('time.timer.start'), ['task_id' => $task->id]);
        $session = $this->latestSession();

        $this->actingAs($this->bruno);
        $this->post(route('time.timer.pause', $session))->assertNotFound();
        $this->post(route('time.timer.stop', $session))->assertNotFound();
        $this->delete(route('time.timer.destroy', $session))->assertNotFound();
        $this->post(route('time.timer.start'), ['task_id' => $task->id])->assertSessionHasErrors('task_id');

        $this->get(route('time.timer.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('timer', null)
                ->has('open_sessions', 0));

        $this->assertSame(TimerStatus::Running, $session->refresh()->status);
    }

    public function test_stopped_sessions_can_be_deleted_without_touching_entries(): void
    {
        TimeEntry::factory()->create(['task_id' => $this->task->id]);
        $this->start();
        $session = $this->latestSession();

        $this->delete(route('time.timer.destroy', $session))->assertSessionHasErrors('timer');
        $this->post(route('time.timer.stop', $session));
        $this->delete(route('time.timer.destroy', $session))->assertSessionHasNoErrors();

        $this->assertModelMissing($session);
        $this->assertDatabaseCount('time_entries', 1);
    }
}
