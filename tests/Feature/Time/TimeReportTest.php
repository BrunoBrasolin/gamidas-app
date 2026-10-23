<?php

namespace Tests\Feature\Time;

use App\Enums\TaskGoalType;
use App\Enums\TimerStatus;
use App\Models\DailyNote;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\TimerSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class TimeReportTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    private Task $gamidas;

    private Task $rust;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday; the week runs Monday 19/10 to Sunday 25/10.
        Date::setTestNow('2026-10-21 10:00:00');
        $this->createHousehold();
        $this->gamidas = Task::factory()->for($this->bruno)->create(['title' => 'Gamidas']);
        $this->rust = Task::factory()->for($this->bruno)->timeGoal(10)->create(['title' => 'Rust']);

        $this->entry($this->gamidas, '2026-10-19', 180);
        $this->entry($this->rust, '2026-10-19', 60);
        $this->entry($this->gamidas, '2026-10-20', 120);
        $this->entry($this->rust, '2026-10-21', 30);
        $this->entry($this->rust, '2026-10-21', 30);
        $this->entry($this->gamidas, '2026-10-26', 45);   // next week
        $this->entry($this->rust, '2026-09-30', 100);     // previous month

        // Another user's time and a timer session must never be counted.
        $this->entry(Task::factory()->for($this->leticia)->create(), '2026-10-20', 500, $this->leticia);
        TimerSession::query()->create([
            'user_id' => $this->bruno->id,
            'task_id' => $this->gamidas->id,
            'status' => TimerStatus::Stopped,
            'started_at' => '2026-10-20 08:00:00',
            'ended_at' => '2026-10-20 18:00:00',
            'accumulated_seconds' => 36_000,
        ]);
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    private function entry(Task $task, string $date, int $minutes, ?User $user = null): TimeEntry
    {
        return TimeEntry::factory()->create([
            'user_id' => ($user ?? $this->bruno)->id,
            'task_id' => $task->id,
            'entry_date' => $date,
            'duration_minutes' => $minutes,
        ]);
    }

    public function test_the_weekly_sheet_totals_by_day_and_task(): void
    {
        DailyNote::query()->create(['user_id' => $this->bruno->id, 'note_date' => '2026-10-20', 'content' => "## Terça\nBom dia"]);

        $this->actingAs($this->bruno)
            ->get(route('time.sheet', ['date' => '2026-10-21']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('time/sheet')
                ->where('filters.view', 'week')
                ->where('period.from', '2026-10-19')
                ->where('period.to', '2026-10-25')
                ->where('period.previous', '2026-10-12')
                ->has('sheet.days', 7)
                ->where('sheet.days.0.total_minutes', 240)
                ->where('sheet.days.1.total_minutes', 120)
                ->where('sheet.days.1.note', 'Terça Bom dia')
                ->where('sheet.days.2.total_minutes', 60)
                ->where('sheet.days.2.is_today', true)
                ->where('sheet.days.3.total_minutes', 0)
                ->where('sheet.tasks.0.title', 'Gamidas')
                ->where('sheet.tasks.0.total_minutes', 300)
                ->where('sheet.tasks.1.total_minutes', 120)
                ->where('sheet.total_minutes', 420));
    }

    public function test_the_monthly_sheet_and_filters(): void
    {
        $this->actingAs($this->bruno)
            ->get(route('time.sheet', ['view' => 'month', 'date' => '2026-10-15', 'task' => $this->rust->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.from', '2026-10-01')
                ->where('period.to', '2026-10-31')
                ->has('sheet.days', 31)
                ->has('sheet.tasks', 1)
                ->where('sheet.tasks.0.title', 'Rust')
                ->where('sheet.total_minutes', 120));

        $this->get(route('time.sheet', ['view' => 'month', 'date' => '2026-10-15', 'sort' => 'total']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sheet.tasks.0.title', 'Gamidas')
                ->where('sheet.total_minutes', 465));
    }

    public function test_archived_tasks_with_history_still_appear_in_the_sheet(): void
    {
        $this->rust->update(['archived_at' => Date::now()]);
        Task::factory()->for($this->bruno)->archived()->create(['title' => 'Sem histórico']);

        $this->actingAs($this->bruno)
            ->get(route('time.sheet', ['date' => '2026-10-21']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('sheet.tasks', 2)
                ->where('sheet.tasks.1.title', 'Rust')
                ->where('sheet.tasks.1.status', 'archived')
                ->where('sheet.total_minutes', 420));
    }

    public function test_reports_use_manual_entries_only(): void
    {
        $this->actingAs($this->bruno)
            ->get(route('time.reports.index', ['from' => '2026-10-19', 'to' => '2026-10-21']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('time/reports')
                ->where('fixed.today_minutes', 60)
                ->where('fixed.week_minutes', 420)
                ->where('fixed.month_minutes', 420)
                ->where('period.days', 3)
                ->where('period.days_with_entries', 3)
                ->where('period.total_minutes', 420)
                ->where('period.average_minutes', 140)
                ->where('period.previous.from', '2026-10-16')
                ->where('period.previous.to', '2026-10-18')
                ->where('period.previous.total_minutes', 0)
                ->where('by_task.0.title', 'Gamidas')
                ->where('by_task.0.minutes', 300)
                ->where('by_task.0.share', 7142)
                ->where('by_task.1.minutes', 120)
                ->where('task_counts.active', 2)
                ->where('heatmap.days.2026-10-19', 240)
                ->has('goals', 1)
                ->where('goals.0.progress.recorded_minutes', 220)
                ->where('goals.0.progress.percent', 36)
                ->where('goals.0.progress.remaining_minutes', 380));
    }

    public function test_reports_filter_by_task_and_handle_empty_periods(): void
    {
        $this->actingAs($this->bruno)
            ->get(route('time.reports.index', ['from' => '2026-10-01', 'to' => '2026-10-31', 'task' => $this->rust->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.total_minutes', 60 + 30 + 30)
                ->has('by_task', 1)
                ->where('period.previous.total_minutes', 100));

        $this->get(route('time.reports.index', ['from' => '2025-01-01', 'to' => '2025-01-31']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.total_minutes', 0)
                ->where('period.average_minutes', 0)
                ->where('period.average_active_minutes', 0)
                ->has('by_task', 0));
    }

    public function test_invalid_report_periods_fall_back_to_the_current_month(): void
    {
        $this->actingAs($this->bruno)
            ->get(route('time.reports.index', ['from' => '2026-10-21', 'to' => '2026-10-01']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.from', '2026-10-01')
                ->where('filters.to', '2026-10-21'));

        $this->get(route('time.reports.index', ['from' => '2020-01-01', 'to' => '2026-10-21']))
            ->assertInertia(fn (Assert $page) => $page->where('filters.from', '2026-10-01'));
    }

    public function test_reports_and_sheets_only_include_the_users_own_data(): void
    {
        $this->actingAs($this->leticia)
            ->get(route('time.reports.index', ['from' => '2026-10-01', 'to' => '2026-10-31']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.total_minutes', 500)
                ->has('by_task', 1)
                ->has('goals', 0));

        // Filtering by someone else's task is ignored.
        $this->get(route('time.sheet', ['date' => '2026-10-21', 'task' => $this->gamidas->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.task', null)
                ->where('sheet.total_minutes', 500)
                ->has('sheet.tasks', 1));
    }

    public function test_the_overview_summarizes_today_the_week_and_active_goals(): void
    {
        $this->rust->update(['goal_type' => TaskGoalType::Time]);

        $this->actingAs($this->bruno)
            ->get(route('time.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('time/index')
                ->where('today_minutes', 60)
                ->where('week.total_minutes', 420)
                ->where('month_minutes', 420)
                ->has('week.days', 7)
                ->has('tasks', 2)
                ->has('recent_entries', 7)
                ->where('recent_entries.0.date', '2026-10-26')
                ->where('today_note', null)
                ->where('timer', null));
    }

    public function test_pages_require_authentication_and_a_household(): void
    {
        $this->get(route('time.index'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get(route('time.index'))
            ->assertForbidden();
    }
}
