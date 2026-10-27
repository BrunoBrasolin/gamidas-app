<?php

namespace App\Http\Requests\Knowledge;

use App\Models\KnowledgeSubject;

/**
 * A file uploaded as a new page of a subject, optionally inside one of its
 * categories. The file is checked like any other attachment.
 */
class KnowledgeFilePageRequest extends KnowledgeAttachmentRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var KnowledgeSubject|null $subject */
        $subject = $this->route('subject');

        return [
            ...parent::rules(),
            'category_id' => ['nullable', 'integer', $this->categoryOf($subject?->id)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [...parent::attributes(), 'category_id' => 'categoria'];
    }
}
