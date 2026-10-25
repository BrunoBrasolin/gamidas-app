<?php

namespace Tests\Feature\Shows;

use App\Models\Show;
use App\Models\ShowChecklistItem;
use App\Services\ShowChecklistService;
use Database\Seeders\ShowChecklistSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class ShowChecklistTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createHousehold();
    }

    /**
     * @return list<string>
     */
    private function labels(): array
    {
        return $this->household->showChecklistItems()->pluck('label')->all();
    }

    public function test_the_checklist_is_saved_from_text_one_item_per_line(): void
    {
        $this->actingAs($this->bruno)
            ->put(route('shows.checklist.update'), ['items' => "1. Capa de chuva\n\n  CNH  \r\nLeque\ncnh\n"])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('shows.checklist.edit'));

        $this->assertSame(['Capa de chuva', 'CNH', 'Leque'], $this->labels());

        $this->actingAs($this->leticia)
            ->get(route('shows.checklist.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('shows/checklist')
                ->where('items', ['Capa de chuva', 'CNH', 'Leque']));
    }

    public function test_kept_items_keep_their_checks_and_removed_ones_lose_them(): void
    {
        app(ShowChecklistService::class)->sync($this->household, ['Capa de chuva', 'CNH', 'Leque']);
        $show = Show::factory()->for($this->household)->create();
        [$capa, $cnh, $leque] = $this->household->showChecklistItems()->get()->all();
        $show->checkedItems()->attach([$capa->id, $leque->id]);

        $this->actingAs($this->bruno)
            ->put(route('shows.checklist.update'), ['items' => ['Leque', 'capa de chuva', 'Meia extra']])
            ->assertSessionHasNoErrors();

        $this->assertSame(['Leque', 'capa de chuva', 'Meia extra'], $this->labels());
        $this->assertSame($capa->id, ShowChecklistItem::query()->where('label', 'capa de chuva')->value('id'));
        $this->assertModelMissing($cnh);
        $this->assertEqualsCanonicalizing([$capa->id, $leque->id], $show->checkedItems()->pluck('show_checklist_items.id')->all());
    }

    public function test_the_checklist_is_validated(): void
    {
        $this->actingAs($this->bruno)
            ->put(route('shows.checklist.update'), ['items' => str_repeat('a', 121)])
            ->assertSessionHasErrors('items');

        $this->actingAs($this->bruno)
            ->put(route('shows.checklist.update'), ['items' => array_map(fn ($i) => "Item {$i}", range(1, 101))])
            ->assertSessionHasErrors('items');

        $this->assertSame([], $this->labels());
    }

    public function test_items_are_checked_and_unchecked_for_each_show(): void
    {
        app(ShowChecklistService::class)->sync($this->household, ['Capa de chuva', 'CNH']);
        [$capa, $cnh] = $this->household->showChecklistItems()->get()->all();
        $show = Show::factory()->for($this->household)->create();
        $other = Show::factory()->for($this->household)->create();

        $this->actingAs($this->leticia)
            ->put(route('shows.checklist.check', [$show, $cnh]), ['checked' => true])
            ->assertRedirect();
        $this->actingAs($this->leticia)
            ->put(route('shows.checklist.check', [$show, $cnh]), ['checked' => true]);

        $this->actingAs($this->bruno)
            ->get(route('shows.show', $show))
            ->assertInertia(fn (Assert $page) => $page
                ->where('checklist', [
                    ['id' => $capa->id, 'label' => 'Capa de chuva', 'checked' => false],
                    ['id' => $cnh->id, 'label' => 'CNH', 'checked' => true],
                ]));
        $this->assertSame(0, $other->checkedItems()->count());

        $this->actingAs($this->bruno)
            ->put(route('shows.checklist.check', [$show, $cnh]), ['checked' => false]);

        $this->assertSame(0, $show->checkedItems()->count());
    }

    public function test_other_households_cannot_touch_the_checklist(): void
    {
        $outsider = $this->createOutsider();
        app(ShowChecklistService::class)->sync($this->household, ['CNH']);
        $item = $this->household->showChecklistItems()->sole();
        $show = Show::factory()->for($this->household)->create();
        $foreignShow = Show::factory()->for($outsider->household())->create();

        $this->actingAs($outsider)
            ->put(route('shows.checklist.check', [$show, $item]), ['checked' => true])
            ->assertNotFound();

        $this->actingAs($outsider)
            ->put(route('shows.checklist.check', [$foreignShow, $item]), ['checked' => true])
            ->assertNotFound();

        $this->actingAs($outsider)
            ->get(route('shows.checklist.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('items', []));

        $this->assertSame(0, $item->newQuery()->join('show_checklist_checks', 'show_checklist_items.id', '=', 'show_checklist_checks.show_checklist_item_id')->count());
    }

    public function test_the_seeder_adds_the_default_items_once(): void
    {
        app(ShowChecklistService::class)->sync($this->household, ['Leque', 'Ingresso']);

        app(ShowChecklistSeeder::class)->run();
        app(ShowChecklistSeeder::class)->run();

        $labels = $this->labels();
        $this->assertCount(17, $labels);
        $this->assertSame(['Leque', 'Ingresso', 'Capa de chuva'], array_slice($labels, 0, 3));
        $this->assertSame('Remédio dor de cabeça', end($labels));
    }
}
