<?php

namespace Tests\Feature\Roulette;

use App\Enums\RouletteCategory;
use App\Models\RoulettePlace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class RoulettePlaceTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createHousehold();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('roulette.index'))->assertRedirect(route('login'));
        $this->post(route('roulette.places.store'), ['category' => 'lunch', 'name' => 'Pizzaria'])->assertRedirect(route('login'));
    }

    public function test_the_page_lists_the_three_categories_with_the_households_places_only(): void
    {
        RoulettePlace::factory()->for($this->household)->create(['category' => RouletteCategory::Lunch, 'name' => 'Cantina', 'created_by_user_id' => $this->leticia->id]);
        RoulettePlace::factory()->for($this->household)->create(['category' => RouletteCategory::Dessert, 'name' => 'Sorveteria']);
        RoulettePlace::factory()->create(['household_id' => $this->createOutsider()->householdId(), 'name' => 'Lugar de fora']);

        $this->actingAs($this->bruno)
            ->get(route('roulette.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('roulette/index', false)
                ->where('categories.0.value', 'lunch')
                ->where('categories.0.label', 'Almoço')
                ->has('categories.0.places', 1)
                ->where('categories.0.places.0.name', 'Cantina')
                ->where('categories.0.places.0.created_by', 'Letícia')
                ->where('categories.1.value', 'dessert')
                ->where('categories.1.label', 'Sobremesa')
                ->has('categories.1.places', 1)
                ->where('categories.2.value', 'dinner')
                ->where('categories.2.label', 'Jantar')
                ->has('categories.2.places', 0));
    }

    public function test_any_member_can_add_a_place_to_a_category(): void
    {
        $this->actingAs($this->leticia)
            ->post(route('roulette.places.store'), ['category' => 'dinner', 'name' => '  Hamburgueria   do  Zé '])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $place = RoulettePlace::query()->sole();
        $this->assertSame($this->household->id, $place->household_id);
        $this->assertSame(RouletteCategory::Dinner, $place->category);
        $this->assertSame('Hamburgueria do Zé', $place->name);
        $this->assertSame($this->leticia->id, $place->created_by_user_id);

        $this->actingAs($this->bruno)
            ->get(route('roulette.index'))
            ->assertInertia(fn (Assert $page) => $page->where('categories.2.places.0.name', 'Hamburgueria do Zé'));
    }

    public function test_the_same_place_can_be_in_different_categories_but_not_twice_in_one(): void
    {
        RoulettePlace::factory()->for($this->household)->create(['category' => RouletteCategory::Lunch, 'name' => 'Cantina']);

        $this->actingAs($this->bruno)
            ->post(route('roulette.places.store'), ['category' => 'lunch', 'name' => 'cantina'])
            ->assertSessionHasErrors(['name' => 'Este lugar já está na roleta desta categoria.']);

        $this->actingAs($this->bruno)
            ->post(route('roulette.places.store'), ['category' => 'dinner', 'name' => 'Cantina'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, RoulettePlace::query()->count());
    }

    public function test_a_place_with_the_same_name_in_another_household_does_not_conflict(): void
    {
        RoulettePlace::factory()->create(['household_id' => $this->createOutsider()->householdId(), 'category' => RouletteCategory::Lunch, 'name' => 'Cantina']);

        $this->actingAs($this->bruno)
            ->post(route('roulette.places.store'), ['category' => 'lunch', 'name' => 'Cantina'])
            ->assertSessionHasNoErrors();
    }

    public function test_the_place_is_validated(): void
    {
        $this->actingAs($this->bruno)
            ->post(route('roulette.places.store'), ['category' => 'breakfast', 'name' => ''])
            ->assertSessionHasErrors(['category', 'name']);

        $this->actingAs($this->bruno)
            ->post(route('roulette.places.store'), ['category' => 'lunch', 'name' => str_repeat('a', 81)])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, RoulettePlace::query()->count());
    }

    public function test_any_member_can_remove_a_place(): void
    {
        $place = RoulettePlace::factory()->for($this->household)->create(['created_by_user_id' => $this->bruno->id]);

        $this->actingAs($this->leticia)
            ->delete(route('roulette.places.destroy', $place))
            ->assertRedirect();

        $this->assertModelMissing($place);
    }

    public function test_places_of_another_household_cannot_be_removed(): void
    {
        $place = RoulettePlace::factory()->create(['household_id' => $this->createOutsider()->householdId()]);

        $this->actingAs($this->bruno)
            ->delete(route('roulette.places.destroy', $place))
            ->assertNotFound();

        $this->assertModelExists($place);
    }
}
