<?php

namespace App\Http\Requests\Study;

/**
 * The full order of a subject's table of contents.
 */
class StudyArrangeRequest extends StudyRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'chapters' => ['present', 'array', 'max:500'],
            'chapters.*' => ['integer'],
            'pages' => ['present', 'array', 'max:2000'],
            'pages.*.id' => ['required', 'integer'],
            'pages.*.chapter_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return list<int>
     */
    public function chapterIds(): array
    {
        return array_values(array_map('intval', (array) $this->validated('chapters')));
    }

    /**
     * @return list<array{id: int, chapter_id: int|null}>
     */
    public function pages(): array
    {
        return array_values(array_map(fn (array $page) => [
            'id' => (int) $page['id'],
            'chapter_id' => isset($page['chapter_id']) ? (int) $page['chapter_id'] : null,
        ], (array) $this->validated('pages')));
    }
}
