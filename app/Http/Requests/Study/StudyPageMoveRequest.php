<?php

namespace App\Http\Requests\Study;

use App\Models\StudySubject;

/**
 * Move a page to a chapter of any of the user's subjects (or to the pages
 * without chapter of that subject).
 */
class StudyPageMoveRequest extends StudyRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $subjectId = $this->input('subject_id');

        return [
            'subject_id' => ['required', 'integer', $this->ownedSubject()],
            'chapter_id' => ['nullable', 'integer', $this->chapterOf(is_numeric($subjectId) ? (int) $subjectId : null)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['subject_id' => 'matéria', 'chapter_id' => 'capítulo'];
    }

    public function targetSubject(): StudySubject
    {
        return StudySubject::query()->where('user_id', $this->user()?->id)->findOrFail((int) $this->validated('subject_id'));
    }
}
