<?php

namespace App\Http\Requests\Knowledge;

use App\Models\KnowledgeSubject;

/**
 * Move a page to a category of any of the user's subjects (or to the pages
 * without category of that subject).
 */
class KnowledgePageMoveRequest extends KnowledgeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $subjectId = $this->input('subject_id');

        return [
            'subject_id' => ['required', 'integer', $this->ownedSubject()],
            'category_id' => ['nullable', 'integer', $this->categoryOf(is_numeric($subjectId) ? (int) $subjectId : null)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['subject_id' => 'matéria', 'category_id' => 'categoria'];
    }

    public function targetSubject(): KnowledgeSubject
    {
        return KnowledgeSubject::query()->where('user_id', $this->user()?->id)->findOrFail((int) $this->validated('subject_id'));
    }
}
