<?php

namespace Tests\Feature\Time;

use App\Models\TimeCategory;
use App\Models\TimeLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class TimeReportTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday.
        Date::setTestNow('2026-10-21 10:00:00');
        $this->createHousehold();
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_the_goal_is_judged_on_business_days_and_off_days_worked_are_reported(): void
    {
        $mega = TimeCategory::factory()->for($this->bruno)->create(['daily_goal_seconds' => 14_400, 'created_at' => '2026-10-01 09:00:00']);
        $find = TimeCategory::factory()->for($this->bruno)->create(['created_at' => '2026-10-01 09:00:00']);

        $log = fn (TimeCategory $category, string $date, int $seconds) => TimeLog::factory()
            ->create(['time_category_id' => $category->id, 'log_date' => $date, 'seconds' => $seconds]);

        foreach (['05', '08', '09', '13', '14', '15', '16', '19', '20'] as $day) {
            $log($mega, "2026-10-{$day}", 14_400);
        }

        $log($mega, '2026-10-06', 3600);    // below the goal; the 7th has nothing
        $log($find, '2026-10-10', 3600);    // Saturday
        $log($mega, '2026-10-12', 7200);    // Nossa Senhora Aparecida
        $log($mega, '2026-10-21', 1000);    // today, still running

        $this->actingAs($this->bruno)
            ->get(route('time.reports.index', ['from' => '2026-10-05', 'to' => '2026-10-23']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('time/reports')
                ->where('goals.has_goal', true)
                ->where('goals.business_days', 14)
                ->where('goals.met', 9)
                ->where('goals.missed', 2)
                ->where('goals.pending', 1)
                ->where('goals.future', 2)
                ->where('goals.rate', 8181)
                ->where('goals.shortfall_seconds', 10_800 + 14_400)
                ->where('goals.current_streak', 8)
                ->where('goals.best_streak', 8)
                ->where('goals.off_days_worked', 2)
                ->where('goals.off_seconds', 10_800)
                ->where('by_day.1.status', 'missed')
                ->where('by_day.1.goal_seconds', 14_400)
                ->where('by_day.1.shortfall_seconds', 10_800)
                ->where('by_day.5.status', 'off_worked')
                ->where('by_day.6.status', 'off')
                ->where('by_day.7.status', 'off_worked')
                ->where('by_day.7.holiday', 'Nossa Senhora Aparecida')
                ->where('by_day.7.goal_seconds', null)
                ->where('by_day.16.status', 'pending'));
    }

    public function test_a_goal_only_applies_while_its_category_exists_and_is_active(): void
    {
        // The old one is archived on the 14th, the day the new one is created.
        TimeCategory::factory()->for($this->bruno)->create(['daily_goal_seconds' => 3600, 'created_at' => '2026-10-14 09:00:00']);
        TimeCategory::factory()->for($this->bruno)->create([
            'daily_goal_seconds' => 3600,
            'created_at' => '2026-10-01 09:00:00',
            'archived_at' => '2026-10-14 08:00:00',
        ]);

        $this->actingAs($this->bruno)
            ->get(route('time.reports.index', ['from' => '2026-10-12', 'to' => '2026-10-16']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('by_day.0.status', 'off')
                ->where('by_day.1.status', 'missed')
                ->where('by_day.1.goal_seconds', 3600)
                ->where('by_day.2.status', 'missed')
                ->where('by_day.2.goal_seconds', 3600)
                ->where('by_day.3.status', 'missed')
                ->where('by_day.4.status', 'missed'));
    }

    public function test_without_goals_business_days_have_no_verdict(): void
    {
        TimeCategory::factory()->for($this->bruno)->create();

        $this->actingAs($this->bruno)
            ->get(route('time.reports.index', ['from' => '2026-10-19', 'to' => '2026-10-21']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('goals.has_goal', false)
                ->where('goals.rate', null)
                ->where('by_day.0.status', 'no_goal'));
    }
}
