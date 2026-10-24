<?php

namespace App\Http\Presenters;

use App\Enums\StudyPageStatus;
use App\Models\StudyAttachment;
use App\Models\StudyChapter;
use App\Models\StudyPage;
use App\Models\StudySubject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Shapes study library records for the frontend (resources/js/types/study.ts).
 */
class StudyPresenter
{
    /**
     * Counts loaded with every subject card.
     *
     * @return array<int|string, mixed>
     */
    public static function subjectCounts(): array
    {
        $live = fn (Builder $query) => $query->whereNull('archived_at');

        return [
            'pages' => $live,
            'chapters',
            'attachments' => fn (Builder $query) => $query->whereNotNull('page_id')->whereNull('detached_at'),
            'pages as reviewed_count' => fn (Builder $query) => $live($query)->where('status', StudyPageStatus::Reviewed),
            'pages as in_progress_count' => fn (Builder $query) => $live($query)->where('status', StudyPageStatus::InProgress),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function subject(StudySubject $subject): array
    {
        $pages = (int) ($subject->getAttribute('pages_count') ?? 0);

        return [
            'id' => $subject->id,
            'title' => $subject->title,
            'description' => $subject->description,
            'accent_color' => $subject->accent_color->value,
            'cover_url' => $subject->cover_attachment_id === null ? null : self::fileUrl($subject->cover_attachment_id),
            'is_pinned' => $subject->is_pinned,
            'sort_order' => $subject->sort_order,
            'archived' => $subject->archived_at !== null,
            'updated_at' => $subject->updated_at?->toIso8601String(),
            'last_opened_at' => $subject->last_opened_at?->toIso8601String(),
            'counts' => [
                'pages' => $pages,
                'chapters' => (int) ($subject->getAttribute('chapters_count') ?? 0),
                'attachments' => (int) ($subject->getAttribute('attachments_count') ?? 0),
                'reviewed' => (int) ($subject->getAttribute('reviewed_count') ?? 0),
                'in_progress' => (int) ($subject->getAttribute('in_progress_count') ?? 0),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function chapter(StudyChapter $chapter): array
    {
        return ['id' => $chapter->id, 'title' => $chapter->title, 'sort_order' => $chapter->sort_order];
    }

    /**
     * A page as listed in tables of contents, search results and lists.
     *
     * @return array<string, mixed>
     */
    public static function pageSummary(StudyPage $page): array
    {
        return [
            'id' => $page->id,
            'subject_id' => $page->subject_id,
            'chapter_id' => $page->chapter_id,
            'title' => $page->title,
            'status' => $page->status->value,
            'sort_order' => $page->sort_order,
            'bookmarked' => $page->bookmarked_at !== null,
            'archived' => $page->archived_at !== null,
            'attachments_count' => (int) ($page->getAttribute('attachments_count') ?? 0),
            'updated_at' => $page->updated_at?->toIso8601String(),
            'last_read_at' => $page->last_read_at?->toIso8601String(),
        ];
    }

    /**
     * A page with the subject it belongs to, for lists across the library.
     *
     * @return array<string, mixed>
     */
    public static function pageWithSubject(StudyPage $page): array
    {
        return [
            ...self::pageSummary($page),
            'subject' => [
                'id' => $page->subject->id,
                'title' => $page->subject->title,
                'accent_color' => $page->subject->accent_color->value,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function attachment(StudyAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'kind' => $attachment->kind->value,
            'name' => $attachment->filename,
            'size' => $attachment->size,
            'mime' => $attachment->mime_type,
            'width' => $attachment->width,
            'height' => $attachment->height,
            'url' => self::fileUrl($attachment->id),
        ];
    }

    /**
     * Attachments of a page keyed by id, as the editor and reader look them up.
     * Ids start at 1, so a non-empty map is always sent as a JSON object.
     *
     * @param  Collection<int, StudyAttachment>  $attachments
     * @return array<int, array<string, mixed>>
     */
    public static function attachmentMap(Collection $attachments): array
    {
        $map = [];

        foreach ($attachments as $attachment) {
            $map[$attachment->id] = self::attachment($attachment);
        }

        return $map;
    }

    public static function fileUrl(int $attachmentId): string
    {
        return route('study.attachments.show', $attachmentId);
    }
}
