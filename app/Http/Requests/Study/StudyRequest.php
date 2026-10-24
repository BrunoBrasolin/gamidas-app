<?php

namespace App\Http\Requests\Study;

use App\Http\Requests\Finance\FinanceRequest;
use App\Models\StudyChapter;
use App\Models\StudySubject;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Shared helpers for the study library requests. The routed record is
 * authorized before validation (see {@see FinanceRequest}); ids in the payload
 * are only accepted when they belong to the user's own library.
 */
abstract class StudyRequest extends FinanceRequest
{
    protected function ownedSubject(): Exists
    {
        return Rule::exists('study_subjects', 'id')->where('user_id', $this->user()?->id);
    }

    protected function chapterOf(?int $subjectId): Exists
    {
        return Rule::exists('study_chapters', 'id')->where('subject_id', $subjectId ?? 0);
    }

    protected function text(string $key): string
    {
        $value = $this->input($key);

        return is_string($value) ? trim($value) : '';
    }

    public function chapter(StudySubject $subject, string $key = 'chapter_id'): ?StudyChapter
    {
        $id = $this->validated($key);

        return $id === null ? null : $subject->chapters()->findOrFail((int) $id);
    }
}
