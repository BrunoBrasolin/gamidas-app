<?php

namespace Tests\Feature\Time;

use App\Models\TimeCategory;
use App\Models\TimeLog;
use App\Models\TimeTimer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class TimeTimerTest extends TestCase
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

    public function test_start_pause_and_resume_accumulate_elapsed_time(): void
    {
        $category = TimeCategory::factory()->for($this->bruno)->create();

        $this->actingAs($this->bruno)->post(route('time.timer.start', $category))->assertRedirect();
        $this->assertTrue(TimeTimer::query()->sole()->isRunning());

        Date::setTestNow('2026-10-21 10:01:30');
        $this->actingAs($this->bruno)->post(route('time.timer.pause', $category));

        $timer = TimeTimer::query()->sole();
        $this->assertFalse($timer->isRunning());
        $this->assertSame(90, $timer->accumulated_seconds);

        Date::setTestNow('2026-10-21 11:00:00');
        $this->actingAs($this->bruno)->post(route('time.timer.start', $category));
        Date::setTestNow('2026-10-21 11:00:10');

        $this->assertSame(100, TimeTimer::query()->sole()->elapsedSeconds());
    }

    public function test_a_category_never_has_more_than_one_timer(): void
    {
        $category = TimeCategory::factory()->for($this->bruno)->create();

        $this->actingAs($this->bruno)->post(route('time.timer.start', $category));
        Date::setTestNow('2026-10-21 10:00:30');
        $this->actingAs($this->bruno)->post(route('time.timer.start', $category));

        $timer = TimeTimer::query()->sole();
        $this->assertSame('2026-10-21 10:00:00', $timer->started_at->format('Y-m-d H:i:s'));
    }

    public function test_reset_discards_the_timer(): void
    {
        $category = TimeCategory::factory()->for($this->bruno)->create();
        $this->actingAs($this->bruno)->post(route('time.timer.start', $category));

        $this->actingAs($this->bruno)->delete(route('time.timer.reset', $category))->assertRedirect();

        $this->assertSame(0, TimeTimer::query()->count());
        $this->assertSame(0, TimeLog::query()->count());
    }

    public function test_commit_adds_the_elapsed_time_to_todays_cell_and_clears_the_timer(): void
    {
        $category = TimeCategory::factory()->for($this->bruno)->create();
        TimeLog::query()->create([
            'user_id' => $this->bruno->id,
            'time_category_id' => $category->id,
            'log_date' => '2026-10-21',
            'seconds' => 600,
            'note' => 'Mantida',
        ]);

        $this->actingAs($this->bruno)->post(route('time.timer.start', $category));
        Date::setTestNow('2026-10-21 10:05:00');
        $this->actingAs($this->bruno)->post(route('time.timer.commit', $category))->assertRedirect();

        $log = TimeLog::query()->sole();
        $this->assertSame(900, $log->seconds);
        $this->assertSame('Mantida', $log->note);
        $this->assertSame(0, TimeTimer::query()->count());
    }

    public function test_archived_categories_cannot_start_a_timer(): void
    {
        $category = TimeCategory::factory()->archived()->for($this->bruno)->create();

        $this->actingAs($this->bruno)
            ->post(route('time.timer.start', $category))
            ->assertSessionHasErrors('category');

        $this->assertSame(0, TimeTimer::query()->count());
    }

    public function test_other_users_categories_are_not_found(): void
    {
        $category = TimeCategory::factory()->for($this->leticia)->create();

        foreach (['start', 'pause', 'commit'] as $action) {
            $this->actingAs($this->bruno)->post(route("time.timer.$action", $category))->assertNotFound();
        }
        $this->actingAs($this->bruno)->delete(route('time.timer.reset', $category))->assertNotFound();
    }

    public function test_the_sheet_lists_timers_with_their_state(): void
    {
        $category = TimeCategory::factory()->for($this->bruno)->create(['name' => 'MegaZap']);
        TimeCategory::factory()->for($this->leticia)->create();
        $this->actingAs($this->bruno)->post(route('time.timer.start', $category));

        $this->actingAs($this->bruno)
            ->get(route('time.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('timers.items', 1)
                ->where('timers.items.0.name', 'MegaZap')
                ->where('timers.items.0.started_at', Date::now()->getTimestampMs()));
    }
}
