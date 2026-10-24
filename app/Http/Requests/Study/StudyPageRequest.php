<?php

namespace App\Http\Requests\Study;

use App\Models\StudySubject;

/**
 * A new page, optionally inside one of the subject's chapters.
 */
class StudyPageRequest extends StudyRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var StudySubject|null $subject */
        $subject = $this->route('subject');

        return [
            'title' => ['required', 'string', 'max:200'],
            'chapter_id' => ['nullable', 'integer', $this->chapterOf($subject?->id)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['title' => 'título', 'chapter_id' => 'capítulo'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['title' => $this->text('title') ?: 'Nova página']);
    }
}
