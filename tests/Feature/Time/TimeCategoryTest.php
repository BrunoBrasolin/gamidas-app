<?php

namespace Tests\Feature\Time;

use App\Models\TimeCategory;
use App\Models\TimeLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class TimeCategoryTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createHousehold();
    }

    public function test_categories_are_created_for_the_signed_in_user(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('time.categories.store'), ['name' => ' MegaZap ', 'user_id' => $this->leticia->id])
            ->assertSessionHasNoErrors();

        $category = TimeCategory::query()->sole();
        $this->assertSame('MegaZap', $category->name);
        $this->assertSame($this->bruno->id, $category->user_id);
    }

    public function test_names_are_unique_per_user_but_not_across_users(): void
    {
        TimeCategory::factory()->for($this->bruno)->create(['name' => 'MegaZap']);
        TimeCategory::factory()->for($this->leticia)->create(['name' => 'Find Motors']);

        $this->actingAs($this->bruno)
            ->post(route('time.categories.store'), ['name' => 'MegaZap'])
            ->assertSessionHasErrors('name');

        $this->actingAs($this->bruno)
            ->post(route('time.categories.store'), ['name' => 'Find Motors'])
            ->assertSessionHasNoErrors();
    }

    public function test_renaming_keeps_the_history(): void
    {
        $category = TimeCategory::factory()->for($this->bruno)->create(['name' => 'Antigo']);
        TimeLog::factory()->create(['time_category_id' => $category->id]);

        $this->actingAs($this->bruno)
            ->put(route('time.categories.update', $category), ['name' => 'Novo'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Novo', $category->refresh()->name);
        $this->assertSame(1, $category->logs()->count());
    }

    public function test_other_users_categories_answer_404(): void
    {
        $theirs = TimeCategory::factory()->for($this->leticia)->create();

        $this->actingAs($this->bruno)->put(route('time.categories.update', $theirs), ['name' => 'X'])->assertNotFound();
        $this->actingAs($this->bruno)->post(route('time.categories.archive', $theirs))->assertNotFound();
        $this->actingAs($this->bruno)->delete(route('time.categories.destroy', $theirs))->assertNotFound();
    }

    public function test_archive_and_restore(): void
    {
        $category = TimeCategory::factory()->for($this->bruno)->create();

        $this->actingAs($this->bruno)->post(route('time.categories.archive', $category));
        $this->assertTrue($category->refresh()->isArchived());

        $this->actingAs($this->bruno)->delete(route('time.categories.archive', $category));
        $this->assertFalse($category->refresh()->isArchived());
    }

    public function test_only_categories_without_history_can_be_deleted(): void
    {
        $used = TimeCategory::factory()->for($this->bruno)->create();
        $empty = TimeCategory::factory()->for($this->bruno)->create();
        TimeLog::factory()->create(['time_category_id' => $used->id]);

        $this->actingAs($this->bruno)
            ->delete(route('time.categories.destroy', $used))
            ->assertSessionHasErrors('category');
        $this->actingAs($this->bruno)
            ->delete(route('time.categories.destroy', $empty))
            ->assertSessionHasNoErrors();

        $this->assertModelExists($used);
        $this->assertModelMissing($empty);
    }

    public function test_the_daily_goal_is_optional_and_can_be_set_and_cleared(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('time.categories.store'), ['name' => 'MegaZap', 'daily_goal_seconds' => 21_600])
            ->assertSessionHasNoErrors();

        $category = TimeCategory::query()->sole();
        $this->assertSame(21_600, $category->daily_goal_seconds);

        $this->actingAs($this->bruno)
            ->put(route('time.categories.update', $category), ['name' => 'MegaZap', 'daily_goal_seconds' => ''])
            ->assertSessionHasNoErrors();

        $this->assertNull($category->fresh()->daily_goal_seconds);
    }

    public function test_the_daily_goal_must_fit_in_a_day(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('time.categories.store'), ['name' => 'MegaZap', 'daily_goal_seconds' => 86_401])
            ->assertSessionHasErrors('daily_goal_seconds');
    }
}
