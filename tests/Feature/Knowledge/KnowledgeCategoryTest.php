<?php

namespace Tests\Feature\Knowledge;

use App\Models\KnowledgeCategory;
use App\Models\KnowledgePage;
use App\Models\KnowledgeSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class KnowledgeCategoryTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    private KnowledgeSubject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createHousehold();
        $this->subject = KnowledgeSubject::factory()->for($this->leticia)->create(['title' => 'Biologia']);
    }

    private function category(string $title, ?KnowledgeCategory $parent = null, int $sortOrder = 1): KnowledgeCategory
    {
        return $this->subject->categories()->create(['title' => $title, 'parent_id' => $parent?->id, 'sort_order' => $sortOrder]);
    }

    /**
     * A chain of nested categories, the first one at the top level.
     *
     * @return list<KnowledgeCategory>
     */
    private function chain(int $levels): array
    {
        $chain = [];

        for ($level = 1; $level <= $levels; $level++) {
            $chain[] = $this->category("Nível {$level}", $chain[$level - 2] ?? null);
        }

        return $chain;
    }

    public function test_categories_are_created_at_the_top_level_or_inside_another(): void
    {
        $this->actingAs($this->leticia)
            ->post(route('knowledge.categories.store', $this->subject), ['title' => ' Célula '])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $cell = $this->subject->categories()->sole();
        $this->assertSame(['Célula', null, 1], [$cell->title, $cell->parent_id, $cell->sort_order]);

        $this->actingAs($this->leticia)->post(route('knowledge.categories.store', $this->subject), ['title' => 'Organelas', 'parent_id' => $cell->id]);
        $this->actingAs($this->leticia)->post(route('knowledge.categories.store', $this->subject), ['title' => 'Membrana', 'parent_id' => $cell->id]);

        $this->assertSame(
            [['Organelas', 1], ['Membrana', 2]],
            $cell->children()->get()->map(fn (KnowledgeCategory $child) => [$child->title, $child->sort_order])->all(),
        );
    }

    public function test_a_parent_of_another_subject_is_rejected(): void
    {
        $other = KnowledgeSubject::factory()->for($this->leticia)->create();
        $foreign = $other->categories()->create(['title' => 'Outra', 'sort_order' => 1]);

        $this->actingAs($this->leticia)
            ->post(route('knowledge.categories.store', $this->subject), ['title' => 'X', 'parent_id' => $foreign->id])
            ->assertSessionHasErrors('parent_id');

        $this->assertSame(0, $this->subject->categories()->count());
    }

    public function test_nesting_is_limited(): void
    {
        $chain = $this->chain(KnowledgeCategory::MAX_DEPTH);

        $this->actingAs($this->leticia)
            ->post(route('knowledge.categories.store', $this->subject), ['title' => 'Fundo demais', 'parent_id' => end($chain)->id])
            ->assertSessionHasErrors(['parent_id' => 'As categorias podem ter no máximo 5 níveis.']);

        $this->actingAs($this->leticia)
            ->post(route('knowledge.categories.store', $this->subject), ['title' => 'Cabe', 'parent_id' => $chain[KnowledgeCategory::MAX_DEPTH - 2]->id])
            ->assertSessionHasNoErrors();
    }

    public function test_categories_move_with_their_subtree(): void
    {
        $cell = $this->category('Célula');
        $genetics = $this->category('Genética', sortOrder: 2);
        $dna = $this->category('DNA', $genetics);
        $page = KnowledgePage::factory()->for($this->subject, 'subject')->create(['category_id' => $dna->id]);

        $this->actingAs($this->leticia)
            ->put(route('knowledge.categories.move', $genetics), ['parent_id' => $cell->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame([$cell->id, 1], [$genetics->refresh()->parent_id, $genetics->sort_order]);
        $this->assertSame($genetics->id, $dna->refresh()->parent_id);
        $this->assertSame($dna->id, $page->refresh()->category_id);

        $this->actingAs($this->leticia)
            ->put(route('knowledge.categories.move', $genetics), ['parent_id' => null])
            ->assertSessionHasNoErrors();

        $this->assertSame([null, 2], [$genetics->refresh()->parent_id, $genetics->sort_order]);
    }

    public function test_a_category_cannot_go_inside_itself_or_too_deep(): void
    {
        $genetics = $this->category('Genética');
        $dna = $this->category('DNA', $genetics);

        foreach ([$genetics, $dna] as $target) {
            $this->actingAs($this->leticia)
                ->put(route('knowledge.categories.move', $genetics), ['parent_id' => $target->id])
                ->assertSessionHasErrors(['parent_id' => 'Uma categoria não pode ficar dentro dela mesma.']);
        }

        // Genética has two levels, so it fits under level 3 but not under 4.
        $chain = $this->chain(4);

        $this->actingAs($this->leticia)
            ->put(route('knowledge.categories.move', $genetics), ['parent_id' => $chain[3]->id])
            ->assertSessionHasErrors('parent_id');

        $this->actingAs($this->leticia)
            ->put(route('knowledge.categories.move', $genetics), ['parent_id' => $chain[2]->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($chain[2]->id, $genetics->refresh()->parent_id);
    }

    public function test_deleting_a_nested_category_moves_its_pages_and_subcategories_up(): void
    {
        $cell = $this->category('Célula');
        $existing = KnowledgePage::factory()->for($this->subject, 'subject')->create(['category_id' => $cell->id, 'sort_order' => 1]);
        $this->category('Membrana', $cell);
        $organelles = $this->category('Organelas', $cell, 2);
        $mitochondria = $this->category('Mitocôndria', $organelles);
        $page = KnowledgePage::factory()->for($this->subject, 'subject')->create(['category_id' => $organelles->id, 'sort_order' => 1]);

        $this->actingAs($this->leticia)->delete(route('knowledge.categories.destroy', $organelles))->assertRedirect();

        $this->assertNull(KnowledgeCategory::query()->find($organelles->id));
        $this->assertSame([$cell->id, 2], [$page->refresh()->category_id, $page->sort_order]);
        $this->assertSame(1, $existing->refresh()->sort_order);
        $this->assertSame([$cell->id, 3], [$mitochondria->refresh()->parent_id, $mitochondria->sort_order]);
    }

    public function test_deleting_a_top_level_category_moves_its_subcategories_to_the_top(): void
    {
        $cell = $this->category('Célula');
        $this->category('Genética', sortOrder: 2);
        $organelles = $this->category('Organelas', $cell);
        $page = KnowledgePage::factory()->for($this->subject, 'subject')->create(['category_id' => $cell->id]);

        $this->actingAs($this->leticia)->delete(route('knowledge.categories.destroy', $cell))->assertRedirect();

        $this->assertNull($page->refresh()->category_id);
        $this->assertSame([null, 3], [$organelles->refresh()->parent_id, $organelles->sort_order]);
    }

    public function test_reading_order_goes_through_subcategories(): void
    {
        $cell = $this->category('Célula');
        $genetics = $this->category('Genética', sortOrder: 2);
        $organelles = $this->category('Organelas', $cell);
        $make = fn (string $title, ?KnowledgeCategory $category) => KnowledgePage::factory()->for($this->subject, 'subject')->create(['title' => $title, 'category_id' => $category?->id]);

        $loose = $make('Solta', null);
        $dna = $make('DNA', $genetics);
        $mitochondria = $make('Mitocôndria', $organelles);
        $intro = $make('Introdução', $cell);

        $this->actingAs($this->leticia)
            ->get(route('knowledge.pages.show', $mitochondria))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('knowledge/read', false)
                ->where('page.category', 'Célula › Organelas')
                ->where('previous.id', $intro->id)
                ->where('next.id', $dna->id)
                ->where('toc.0.id', $intro->id)
                ->where('toc.3.id', $loose->id)
                ->where('categories.1.parent_id', $cell->id));
    }

    public function test_categories_of_other_users_answer_404(): void
    {
        $theirs = KnowledgeSubject::factory()->for($this->bruno)->create()->categories()->create(['title' => 'X', 'sort_order' => 1]);
        $mine = $this->category('Minha');

        $this->actingAs($this->leticia)->put(route('knowledge.categories.move', $theirs), ['parent_id' => null])->assertNotFound();
        $this->actingAs($this->leticia)->put(route('knowledge.categories.update', $theirs), ['title' => 'Y'])->assertNotFound();
        $this->actingAs($this->leticia)->delete(route('knowledge.categories.destroy', $theirs))->assertNotFound();

        $this->actingAs($this->leticia)
            ->put(route('knowledge.categories.move', $mine), ['parent_id' => $theirs->id])
            ->assertSessionHasErrors('parent_id');
    }
}
