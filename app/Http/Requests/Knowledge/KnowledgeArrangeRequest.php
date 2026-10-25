<?php

namespace App\Http\Requests\Knowledge;

/**
 * The full order of a subject's table of contents.
 */
class KnowledgeArrangeRequest extends KnowledgeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'categories' => ['present', 'array', 'max:500'],
            'categories.*' => ['integer'],
            'pages' => ['present', 'array', 'max:2000'],
            'pages.*.id' => ['required', 'integer'],
            'pages.*.category_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return list<int>
     */
    public function categoryIds(): array
    {
        return array_values(array_map('intval', (array) $this->validated('categories')));
    }

    /**
     * @return list<array{id: int, category_id: int|null}>
     */
    public function pages(): array
    {
        return array_values(array_map(fn (array $page) => [
            'id' => (int) $page['id'],
            'category_id' => isset($page['category_id']) ? (int) $page['category_id'] : null,
        ], (array) $this->validated('pages')));
    }
}
