<?php

namespace App\Support;

use App\Models\KnowledgeCategory;
use Illuminate\Support\Collection;

/**
 * The categories of one subject as a tree. Reading order is depth first:
 * a category, then its subcategories in order, then the next sibling.
 */
final class KnowledgeCategoryTree
{
    /** @var array<int, list<int>> parent id (0 = top level) => child ids in order */
    private array $children = [];

    /** @var array<int, int|null> */
    private array $parents = [];

    /**
     * @param  iterable<KnowledgeCategory>  $categories
     */
    public function __construct(iterable $categories)
    {
        $sorted = Collection::make($categories)->sortBy([['sort_order', 'asc'], ['id', 'asc']]);

        foreach ($sorted as $category) {
            $this->parents[$category->id] = $category->parent_id;
        }

        foreach ($sorted as $category) {
            // A parent outside the subject is treated as top level.
            $parent = $category->parent_id !== null && array_key_exists($category->parent_id, $this->parents) ? $category->parent_id : 0;
            $this->children[$parent][] = $category->id;
        }
    }

    public function has(int $id): bool
    {
        return array_key_exists($id, $this->parents);
    }

    /**
     * Every category id in reading order.
     *
     * @return list<int>
     */
    public function ordered(): array
    {
        return $this->below(0);
    }

    /**
     * Ids of every category inside the given one (not including it).
     *
     * @return list<int>
     */
    public function descendants(int $id): array
    {
        return $this->below($id);
    }

    /**
     * Level of a category: 1 for top level.
     */
    public function depth(int $id): int
    {
        $depth = 0;

        for ($current = $id; $current !== null && $this->has($current) && $depth <= count($this->parents); $current = $this->parents[$current]) {
            $depth++;
        }

        return $depth;
    }

    /**
     * Levels in the subtree of a category, itself included (1 for a leaf).
     */
    public function height(int $id): int
    {
        $height = 0;

        foreach ($this->children[$id] ?? [] as $child) {
            $height = max($height, $this->height($child));
        }

        return $height + 1;
    }

    /**
     * Whether a category (with its subtree) may be placed inside a parent:
     * never inside itself or its own subcategories, nor deeper than
     * {@see KnowledgeCategory::MAX_DEPTH}. Null moves to the top level.
     */
    public function canPlace(?int $moving, ?int $parent): bool
    {
        if ($parent !== null && ! $this->has($parent)) {
            return false;
        }

        if ($moving !== null && $parent !== null && ($parent === $moving || in_array($parent, $this->descendants($moving), true))) {
            return false;
        }

        $levels = $moving === null ? 1 : $this->height($moving);

        return ($parent === null ? 0 : $this->depth($parent)) + $levels <= KnowledgeCategory::MAX_DEPTH;
    }

    /**
     * @return list<int>
     */
    private function below(int $parent): array
    {
        $ids = [];

        foreach ($this->children[$parent] ?? [] as $child) {
            $ids[] = $child;
            array_push($ids, ...$this->below($child));
        }

        return $ids;
    }
}
