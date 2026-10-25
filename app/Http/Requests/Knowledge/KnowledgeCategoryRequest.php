<?php

namespace App\Http\Requests\Knowledge;

use App\Models\KnowledgeSubject;

/**
 * A new category (optionally inside another one) or a new title.
 */
class KnowledgeCategoryRequest extends KnowledgeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $subject = $this->route('subject');
        $rules = ['title' => ['required', 'string', 'max:160']];

        if ($subject instanceof KnowledgeSubject) {
            $rules['parent_id'] = ['nullable', 'integer', $this->categoryOf($subject->id), $this->placement($subject)];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['title' => 'título da categoria', 'parent_id' => 'categoria de cima'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['title' => $this->text('title')]);
    }
}
