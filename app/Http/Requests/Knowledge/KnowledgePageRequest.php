<?php

namespace App\Http\Requests\Knowledge;

use App\Models\KnowledgeSubject;

/**
 * A new page, optionally inside one of the subject's categories.
 */
class KnowledgePageRequest extends KnowledgeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var KnowledgeSubject|null $subject */
        $subject = $this->route('subject');

        return [
            'title' => ['required', 'string', 'max:200'],
            'category_id' => ['nullable', 'integer', $this->categoryOf($subject?->id)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['title' => 'título', 'category_id' => 'categoria'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['title' => $this->text('title') ?: 'Nova página']);
    }
}
