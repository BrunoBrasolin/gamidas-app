<?php

namespace App\Http\Requests\Knowledge;

use App\Models\KnowledgeCategory;

/**
 * Put a category inside another category of the same subject, or at the top
 * level (no parent).
 */
class KnowledgeCategoryMoveRequest extends KnowledgeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var KnowledgeCategory $category */
        $category = $this->route('category');

        return [
            'parent_id' => ['nullable', 'integer', $this->categoryOf($category->subject_id), $this->placement($category->subject, $category)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['parent_id' => 'categoria de cima'];
    }
}
