<?php

namespace App\Services\Knowledge;

use App\Enums\KnowledgePageStatus;
use App\Models\KnowledgeAttachment;
use App\Models\KnowledgeCategory;
use App\Models\KnowledgePage;
use App\Models\KnowledgeSubject;
use App\Support\KnowledgeContent;
use Illuminate\Support\Facades\DB;

/**
 * Creates, saves, organizes and deletes knowledge pages. Pages are ordered within
 * their category (or among the pages without category) by sort_order.
 */
class KnowledgePageService
{
    public function __construct(private readonly KnowledgeAttachmentService $attachments)
    {
        //
    }

    /**
     * A new page goes to the end of its category.
     */
    public function create(KnowledgeSubject $subject, string $title, ?KnowledgeCategory $category = null): KnowledgePage
    {
        $page = $subject->pages()->create([
            'category_id' => $category?->id,
            'title' => $title,
            'content' => KnowledgeContent::empty(),
            'content_text' => '',
            'sort_order' => $this->nextPosition($subject, $category?->id),
        ]);

        $subject->touch();

        return $page;
    }

    /**
     * Save the editor title and document. The save is refused when the page
     * changed since $revision, unless $force is set (the user chose to keep
     * their version).
     *
     * @param  array<mixed>  $content
     *
     * @throws StaleRevisionException
     */
    public function saveContent(KnowledgePage $page, string $title, array $content, int $revision, bool $force = false): KnowledgePage
    {
        return DB::transaction(function () use ($page, $title, $content, $revision, $force) {
            /** @var KnowledgePage $current */
            $current = KnowledgePage::query()->lockForUpdate()->findOrFail($page->id);

            if (! $force && $current->revision !== $revision) {
                throw new StaleRevisionException($current);
            }

            $owned = $current->attachments()->get()->map(fn (KnowledgeAttachment $attachment) => $attachment->id)->values()->all();
            $document = KnowledgeContent::sanitize($content, $owned);

            $current->update([
                'title' => $title,
                'content' => $document,
                'content_text' => KnowledgeContent::text($document),
                'revision' => $current->revision + 1,
            ]);

            $this->attachments->syncPage($current, KnowledgeContent::attachmentIds($document));
            $current->subject->touch();

            return $current;
        });
    }

    public function rename(KnowledgePage $page, string $title): void
    {
        $page->update(['title' => $title, 'revision' => $page->revision + 1]);
    }

    public function setStatus(KnowledgePage $page, KnowledgePageStatus $status): void
    {
        KnowledgePage::withoutTimestamps(fn () => $page->update(['status' => $status]));
    }

    public function bookmark(KnowledgePage $page, bool $bookmarked): void
    {
        KnowledgePage::withoutTimestamps(fn () => $page->update(['bookmarked_at' => $bookmarked ? now() : null]));
    }

    public function archive(KnowledgePage $page): void
    {
        $page->update(['archived_at' => now()]);
    }

    public function restore(KnowledgePage $page): void
    {
        $page->update(['archived_at' => null]);
    }

    /**
     * Remember that the page was opened in the reader and where it was left.
     */
    public function recordReading(KnowledgePage $page, ?int $position = null): void
    {
        KnowledgePage::withoutTimestamps(fn () => $page->update([
            'last_read_at' => now(),
            ...($position === null ? [] : ['reading_position' => max(0, min(1000, $position))]),
        ]));

        KnowledgeSubject::withoutTimestamps(fn () => $page->subject->update(['last_opened_at' => now()]));
    }

    /**
     * Delete the page and its files.
     */
    public function delete(KnowledgePage $page): void
    {
        DB::transaction(function () use ($page) {
            $this->attachments->delete($page->attachments()->get());
            $page->delete();
        });
    }

    /**
     * Copy a page right after the original, files included.
     */
    public function duplicate(KnowledgePage $page): KnowledgePage
    {
        return DB::transaction(function () use ($page) {
            $page->subject->pages()
                ->where('category_id', $page->category_id)
                ->where('sort_order', '>', $page->sort_order)
                ->increment('sort_order');

            $copy = $page->subject->pages()->create([
                'category_id' => $page->category_id,
                'title' => mb_substr($page->title.' (cópia)', 0, 200),
                'content' => $page->content,
                'content_text' => $page->content_text,
                'sort_order' => $page->sort_order + 1,
                'status' => KnowledgePageStatus::Unread,
            ]);

            $placed = KnowledgeContent::attachmentIds($page->content);
            $map = [];

            foreach ($page->attachments()->whereKey($placed)->get() as $attachment) {
                $map[$attachment->id] = $this->attachments->duplicate($attachment, $copy)->id;
            }

            if ($map !== []) {
                $copy->update(['content' => KnowledgeContent::remapAttachments($copy->content ?? KnowledgeContent::empty(), $map)]);
            }

            return $copy;
        });
    }

    /**
     * Move a page to the end of a category (or of the pages without category),
     * possibly in another subject. Its files follow it.
     */
    public function move(KnowledgePage $page, KnowledgeSubject $subject, ?KnowledgeCategory $category): void
    {
        DB::transaction(function () use ($page, $subject, $category) {
            $fromSubject = $page->subject;

            $page->update([
                'subject_id' => $subject->id,
                'category_id' => $category?->id,
                'sort_order' => $this->nextPosition($subject, $category?->id),
            ]);

            if ($fromSubject->id !== $subject->id) {
                KnowledgeAttachment::query()->where('page_id', $page->id)->update(['subject_id' => $subject->id]);
                $fromSubject->touch();
            }

            $subject->touch();
        });
    }

    /**
     * Apply the order of the table of contents: categories in order (only
     * siblings are compared, so nesting is kept) and, for each page, its
     * category and position. Ids from other subjects are ignored.
     *
     * @param  list<int>  $categoryIds
     * @param  list<array{id: int, category_id: int|null}>  $pages
     */
    public function arrange(KnowledgeSubject $subject, array $categoryIds, array $pages): void
    {
        DB::transaction(function () use ($subject, $categoryIds, $pages) {
            $categories = $subject->categories()->pluck('id')->flip();

            foreach ($categoryIds as $position => $id) {
                if (isset($categories[$id])) {
                    $subject->categories()->whereKey($id)->toBase()->update(['sort_order' => $position + 1]);
                }
            }

            $positions = [];

            foreach ($pages as $entry) {
                $categoryId = $entry['category_id'] !== null && isset($categories[$entry['category_id']]) ? $entry['category_id'] : null;
                $key = $categoryId ?? 0;
                $positions[$key] = ($positions[$key] ?? 0) + 1;

                $subject->pages()->whereKey($entry['id'])->toBase()->update([
                    'category_id' => $categoryId,
                    'sort_order' => $positions[$key],
                ]);
            }

            $subject->touch();
        });
    }

    private function nextPosition(KnowledgeSubject $subject, ?int $categoryId): int
    {
        return (int) $subject->pages()->where('category_id', $categoryId)->max('sort_order') + 1;
    }
}
