<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeCategory;
use App\Models\KnowledgeSubject;
use App\Support\KnowledgeCategoryTree;
use Illuminate\Support\Facades\DB;

/**
 * Categories are optional, nestable groups of pages inside a subject. Where a
 * category may go is checked by the requests ({@see KnowledgeCategoryTree::canPlace()}).
 */
class KnowledgeCategoryService
{
    /**
     * A new category goes to the end of its siblings.
     */
    public function create(KnowledgeSubject $subject, string $title, ?KnowledgeCategory $parent = null): KnowledgeCategory
    {
        return $subject->categories()->create([
            'parent_id' => $parent?->id,
            'title' => $title,
            'sort_order' => $this->nextPosition($subject, $parent?->id),
        ]);
    }

    public function rename(KnowledgeCategory $category, string $title): void
    {
        $category->update(['title' => $title]);
    }

    /**
     * Move a category, with its pages and subcategories, to the end of the
     * children of another category (or of the top level).
     */
    public function move(KnowledgeCategory $category, ?KnowledgeCategory $parent): void
    {
        if ($category->parent_id === $parent?->id) {
            return;
        }

        $category->update([
            'parent_id' => $parent?->id,
            'sort_order' => $this->nextPosition($category->subject, $parent?->id),
        ]);
    }

    /**
     * Deleting a category never deletes its pages or subcategories: they move
     * to the end of the category above it (or, at the top level, to the pages
     * without category and the top-level categories).
     */
    public function delete(KnowledgeCategory $category): void
    {
        DB::transaction(function () use ($category) {
            $subject = $category->subject;
            $lastPage = (int) $subject->pages()->where('category_id', $category->parent_id)->max('sort_order');
            $lastCategory = $this->nextPosition($subject, $category->parent_id) - 1;

            foreach ($category->pages()->orderBy('sort_order')->orderBy('id')->get() as $index => $page) {
                $page->update(['category_id' => $category->parent_id, 'sort_order' => $lastPage + $index + 1]);
            }

            foreach ($category->children()->get() as $index => $child) {
                $child->update(['parent_id' => $category->parent_id, 'sort_order' => $lastCategory + $index + 1]);
            }

            $category->delete();
        });
    }

    private function nextPosition(KnowledgeSubject $subject, ?int $parentId): int
    {
        return (int) $subject->categories()->reorder()->where('parent_id', $parentId)->max('sort_order') + 1;
    }
}
