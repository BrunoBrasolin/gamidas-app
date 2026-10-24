<?php

namespace App\Services\Study;

use App\Models\StudyChapter;
use App\Models\StudySubject;
use Illuminate\Support\Facades\DB;

/**
 * Chapters are optional groups of pages inside a subject.
 */
class StudyChapterService
{
    public function create(StudySubject $subject, string $title): StudyChapter
    {
        return $subject->chapters()->create([
            'title' => $title,
            'sort_order' => (int) $subject->chapters()->max('sort_order') + 1,
        ]);
    }

    public function rename(StudyChapter $chapter, string $title): void
    {
        $chapter->update(['title' => $title]);
    }

    /**
     * Deleting a chapter never deletes its pages: they move to the end of
     * the pages without chapter.
     */
    public function delete(StudyChapter $chapter): void
    {
        DB::transaction(function () use ($chapter) {
            $last = (int) $chapter->subject->pages()->whereNull('chapter_id')->max('sort_order');

            foreach ($chapter->pages()->orderBy('sort_order')->orderBy('id')->get() as $index => $page) {
                $page->update(['chapter_id' => null, 'sort_order' => $last + $index + 1]);
            }

            $chapter->delete();
        });
    }
}
