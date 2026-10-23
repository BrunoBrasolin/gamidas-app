<?php

namespace Tests\Feature\Time;

use App\Models\TimeCategory;
use App\Models\TimeLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class TimeSheetTest extends TestCase
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

    public function test_a_cell_stores_seconds_and_a_markdown_note(): void
    {
        $category = TimeCategory::factory()->for($this->bruno)->create(['name' => 'MegaZap']);

        $this->actingAs($this->bruno)
            ->put(route('time.logs.update'), [
                'category_id' => $category->id,
                'log_date' => '2026-10-20',
                'seconds' => 28_022,
                'note' => "- Reunião\n- Ajustes",
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $log = TimeLog::query()->sole();
        $this->assertSame($this->bruno->id, $log->user_id);
        $this->assertSame(28_022, $log->seconds);
        $this->assertSame("- Reunião\n- Ajustes", $log->note);
        $this->assertSame('2026-10-20', $log->log_date->toDateString());
    }

    public function test_saving_again_updates_the_same_cell_and_keeps_the_note_when_omitted(): void
    {
        $category = TimeCategory::factory()->for($this->bruno)->create();
        $payload = ['category_id' => $category->id, 'log_date' => '2026-10-20'];

        $this->actingAs($this->bruno)->put(route('time.logs.update'), $payload + ['seconds' => 60, 'note' => 'Primeira']);
        $this->actingAs($this->bruno)->put(route('time.logs.update'), $payload + ['seconds' => 120]);

        $log = TimeLog::query()->sole();
        $this->assertSame(120, $log->seconds);
        $this->assertSame('Primeira', $log->note);
    }

    public function test_an_empty_cell_is_removed(): void
    {
        $category = TimeCategory::factory()->for($this->bruno)->create();
        TimeLog::factory()->create(['time_category_id' => $category->id, 'log_date' => '2026-10-20', 'note' => 'x']);

        $this->actingAs($this->bruno)
            ->put(route('time.logs.update'), [
                'category_id' => $category->id,
                'log_date' => '2026-10-20',
                'seconds' => 0,
                'note' => '  ',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('time_logs', 0);
    }

    public function test_a_note_alone_is_kept_without_time(): void
    {
        $category = TimeCategory::factory()->for($this->bruno)->create();

        $this->actingAs($this->bruno)->put(route('time.logs.update'), [
            'category_id' => $category->id,
            'log_date' => '2026-10-20',
            'seconds' => 0,
            'note' => 'Só reunião',
        ]);

        $this->assertSame(0, TimeLog::query()->sole()->seconds);
    }

    public function test_invalid_values_are_rejected(): void
    {
        $category = TimeCategory::factory()->for($this->bruno)->create();

        $this->actingAs($this->bruno)
            ->put(route('time.logs.update'), [
                'category_id' => $category->id,
                'log_date' => '31/10/2026',
                'seconds' => 86_401,
            ])
            ->assertSessionHasErrors(['log_date', 'seconds']);

        $this->assertDatabaseCount('time_logs', 0);
    }

    public function test_other_users_categories_are_rejected(): void
    {
        $theirs = TimeCategory::factory()->for($this->leticia)->create();

        $this->actingAs($this->bruno)
            ->put(route('time.logs.update'), [
                'category_id' => $theirs->id,
                'log_date' => '2026-10-20',
                'seconds' => 60,
            ])
            ->assertSessionHasErrors('category_id');

        $this->assertDatabaseCount('time_logs', 0);
    }

    public function test_archived_categories_only_accept_edits_to_existing_cells(): void
    {
        $category = TimeCategory::factory()->for($this->bruno)->archived()->create();
        TimeLog::factory()->create(['time_category_id' => $category->id, 'log_date' => '2026-10-20']);

        $this->actingAs($this->bruno)
            ->put(route('time.logs.update'), ['category_id' => $category->id, 'log_date' => '2026-10-21', 'seconds' => 60])
            ->assertSessionHasErrors('category');

        $this->actingAs($this->bruno)
            ->put(route('time.logs.update'), ['category_id' => $category->id, 'log_date' => '2026-10-20', 'seconds' => 90])
            ->assertSessionHasNoErrors();

        $this->assertSame(90, TimeLog::query()->sole()->seconds);
    }

    public function test_the_sheet_lists_only_the_users_own_categories_and_totals(): void
    {
        $mega = TimeCategory::factory()->for($this->bruno)->create(['name' => 'MegaZap']);
        $find = TimeCategory::factory()->for($this->bruno)->create(['name' => 'Find Motors']);
        $other = TimeCategory::factory()->for($this->leticia)->create(['name' => 'Segredo']);
        TimeLog::factory()->create(['time_category_id' => $mega->id, 'log_date' => '2026-10-20', 'seconds' => 3600, 'note' => 'a']);
        TimeLog::factory()->create(['time_category_id' => $find->id, 'log_date' => '2026-10-20', 'seconds' => 600]);
        TimeLog::factory()->create(['time_category_id' => $other->id, 'log_date' => '2026-10-20', 'seconds' => 9999]);

        $this->actingAs($this->bruno)
            ->get(route('time.index', ['view' => 'week', 'date' => '2026-10-21']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('time/sheet')
                ->where('sheet.categories.0.name', 'Find Motors')
                ->where('sheet.categories.1.name', 'MegaZap')
                ->has('sheet.categories', 2)
                ->where('sheet.total_seconds', 4200)
                ->where("sheet.cells.2026-10-20.{$mega->id}.note", 'a')
                ->where('sheet.days.1.total_seconds', 4200));
    }

    public function test_the_sheet_requires_login(): void
    {
        $this->get(route('time.index'))->assertRedirect();
    }

    public function test_reports_sum_seconds_by_category(): void
    {
        $mega = TimeCategory::factory()->for($this->bruno)->create();
        $find = TimeCategory::factory()->for($this->bruno)->create();
        TimeLog::factory()->create(['time_category_id' => $mega->id, 'log_date' => '2026-10-05', 'seconds' => 7200]);
        TimeLog::factory()->create(['time_category_id' => $find->id, 'log_date' => '2026-10-06', 'seconds' => 3600]);

        $this->actingAs($this->bruno)
            ->get(route('time.reports.index', ['from' => '2026-10-01', 'to' => '2026-10-21']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('time/reports')
                ->where('period.total_seconds', 10_800)
                ->where('period.days_with_time', 2)
                ->where('by_category.0.category_id', $mega->id)
                ->where('by_category.0.share', 6666)
                ->where('fixed.month_seconds', 10_800));
    }
}
