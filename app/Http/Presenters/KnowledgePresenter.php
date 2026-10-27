<?php

namespace App\Http\Presenters;

use App\Enums\KnowledgePageStatus;
use App\Models\KnowledgeAttachment;
use App\Models\KnowledgeCategory;
use App\Models\KnowledgePage;
use App\Models\KnowledgeSubject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Shapes knowledge base records for the frontend (resources/js/types/knowledge.ts).
 */
class KnowledgePresenter
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
            'categories',
            'attachments' => fn (Builder $query) => $query->whereNull('detached_at')
                ->where(fn (Builder $listed) => $listed->whereNotNull('page_id')->orWhere('is_subject_file', true)),
            'pages as reviewed_count' => fn (Builder $query) => $live($query)->where('status', KnowledgePageStatus::Reviewed),
            'pages as in_progress_count' => fn (Builder $query) => $live($query)->where('status', KnowledgePageStatus::InProgress),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function subject(KnowledgeSubject $subject): array
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
                'categories' => (int) ($subject->getAttribute('categories_count') ?? 0),
                'attachments' => (int) ($subject->getAttribute('attachments_count') ?? 0),
                'reviewed' => (int) ($subject->getAttribute('reviewed_count') ?? 0),
                'in_progress' => (int) ($subject->getAttribute('in_progress_count') ?? 0),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function category(KnowledgeCategory $category): array
    {
        return ['id' => $category->id, 'parent_id' => $category->parent_id, 'title' => $category->title, 'sort_order' => $category->sort_order];
    }

    /**
     * Titles from the top-level category down to the given one ("Célula › Organelas").
     *
     * @param  iterable<KnowledgeCategory>  $categories  every category of the subject
     */
    public static function categoryPath(iterable $categories, ?int $id): ?string
    {
        $byId = [];

        foreach ($categories as $category) {
            $byId[$category->id] = $category;
        }

        $titles = [];

        for ($current = $id; $current !== null && isset($byId[$current]) && count($titles) < count($byId); $current = $byId[$current]->parent_id) {
            array_unshift($titles, $byId[$current]->title);
        }

        return $titles === [] ? null : implode(' › ', $titles);
    }

    /**
     * A page as listed in tables of contents, search results and lists.
     *
     * @return array<string, mixed>
     */
    public static function pageSummary(KnowledgePage $page): array
    {
        return [
            'id' => $page->id,
            'subject_id' => $page->subject_id,
            'category_id' => $page->category_id,
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
     * A page with the subject it belongs to, for lists across the knowledge base.
     *
     * @return array<string, mixed>
     */
    public static function pageWithSubject(KnowledgePage $page): array
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
    public static function attachment(KnowledgeAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'kind' => $attachment->kind->value,
            'name' => $attachment->filename,
            'size' => $attachment->size,
            'mime' => $attachment->mime_type,
            'width' => $attachment->width,
            'height' => $attachment->height,
            'reading_page' => $attachment->reading_page,
            'page_count' => $attachment->page_count,
            'url' => self::fileUrl($attachment->id),
        ];
    }

    /**
     * A file with where it lives: its subject and, when placed in a page,
     * that page.
     *
     * @return array<string, mixed>
     */
    public static function fileWithPlace(KnowledgeAttachment $attachment): array
    {
        return [
            ...self::attachment($attachment),
            'added_at' => $attachment->created_at?->toIso8601String(),
            'subject' => [
                'id' => $attachment->subject->id,
                'title' => $attachment->subject->title,
                'accent_color' => $attachment->subject->accent_color->value,
                'archived' => $attachment->subject->archived_at !== null,
            ],
            'page' => $attachment->page === null ? null : [
                'id' => $attachment->page->id,
                'title' => $attachment->page->title,
                'archived' => $attachment->page->archived_at !== null,
            ],
        ];
    }

    /**
     * Attachments of a page keyed by id, as the editor and reader look them up.
     * Ids start at 1, so a non-empty map is always sent as a JSON object.
     *
     * @param  Collection<int, KnowledgeAttachment>  $attachments
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
        return route('knowledge.attachments.show', $attachmentId);
    }
}
