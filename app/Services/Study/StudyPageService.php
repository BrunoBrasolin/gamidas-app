<?php

namespace App\Services\Study;

use App\Enums\StudyPageStatus;
use App\Models\StudyAttachment;
use App\Models\StudyChapter;
use App\Models\StudyPage;
use App\Models\StudySubject;
use App\Support\StudyContent;
use Illuminate\Support\Facades\DB;

/**
 * Creates, saves, organizes and deletes study pages. Pages are ordered within
 * their chapter (or among the pages without chapter) by sort_order.
 */
class StudyPageService
{
    public function __construct(private readonly StudyAttachmentService $attachments)
    {
        //
    }

    /**
     * A new page goes to the end of its chapter.
     */
    public function create(StudySubject $subject, string $title, ?StudyChapter $chapter = null): StudyPage
    {
        $page = $subject->pages()->create([
            'chapter_id' => $chapter?->id,
            'title' => $title,
            'content' => StudyContent::empty(),
            'content_text' => '',
            'sort_order' => $this->nextPosition($subject, $chapter?->id),
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
    public function saveContent(StudyPage $page, string $title, array $content, int $revision, bool $force = false): StudyPage
    {
        return DB::transaction(function () use ($page, $title, $content, $revision, $force) {
            /** @var StudyPage $current */
            $current = StudyPage::query()->lockForUpdate()->findOrFail($page->id);

            if (! $force && $current->revision !== $revision) {
                throw new StaleRevisionException($current);
            }

            $owned = $current->attachments()->get()->map(fn (StudyAttachment $attachment) => $attachment->id)->values()->all();
            $document = StudyContent::sanitize($content, $owned);

            $current->update([
                'title' => $title,
                'content' => $document,
                'content_text' => StudyContent::text($document),
                'revision' => $current->revision + 1,
            ]);

            $this->attachments->syncPage($current, StudyContent::attachmentIds($document));
            $current->subject->touch();

            return $current;
        });
    }

    public function rename(StudyPage $page, string $title): void
    {
        $page->update(['title' => $title, 'revision' => $page->revision + 1]);
    }

    public function setStatus(StudyPage $page, StudyPageStatus $status): void
    {
        StudyPage::withoutTimestamps(fn () => $page->update(['status' => $status]));
    }

    public function bookmark(StudyPage $page, bool $bookmarked): void
    {
        StudyPage::withoutTimestamps(fn () => $page->update(['bookmarked_at' => $bookmarked ? now() : null]));
    }

    public function archive(StudyPage $page): void
    {
        $page->update(['archived_at' => now()]);
    }

    public function restore(StudyPage $page): void
    {
        $page->update(['archived_at' => null]);
    }

    /**
     * Remember that the page was opened in the reader and where it was left.
     */
    public function recordReading(StudyPage $page, ?int $position = null): void
    {
        StudyPage::withoutTimestamps(fn () => $page->update([
            'last_read_at' => now(),
            ...($position === null ? [] : ['reading_position' => max(0, min(1000, $position))]),
        ]));

        StudySubject::withoutTimestamps(fn () => $page->subject->update(['last_opened_at' => now()]));
    }

    /**
     * Delete the page and its files.
     */
    public function delete(StudyPage $page): void
    {
        DB::transaction(function () use ($page) {
            $this->attachments->delete($page->attachments()->get());
            $page->delete();
        });
    }

    /**
     * Copy a page right after the original, files included.
     */
    public function duplicate(StudyPage $page): StudyPage
    {
        return DB::transaction(function () use ($page) {
            $page->subject->pages()
                ->where('chapter_id', $page->chapter_id)
                ->where('sort_order', '>', $page->sort_order)
                ->increment('sort_order');

            $copy = $page->subject->pages()->create([
                'chapter_id' => $page->chapter_id,
                'title' => mb_substr($page->title.' (cópia)', 0, 200),
                'content' => $page->content,
                'content_text' => $page->content_text,
                'sort_order' => $page->sort_order + 1,
                'status' => StudyPageStatus::Unread,
            ]);

            $placed = StudyContent::attachmentIds($page->content);
            $map = [];

            foreach ($page->attachments()->whereKey($placed)->get() as $attachment) {
                $map[$attachment->id] = $this->attachments->duplicate($attachment, $copy)->id;
            }

            if ($map !== []) {
                $copy->update(['content' => StudyContent::remapAttachments($copy->content ?? StudyContent::empty(), $map)]);
            }

            return $copy;
        });
    }

    /**
     * Move a page to the end of a chapter (or of the pages without chapter),
     * possibly in another subject. Its files follow it.
     */
    public function move(StudyPage $page, StudySubject $subject, ?StudyChapter $chapter): void
    {
        DB::transaction(function () use ($page, $subject, $chapter) {
            $fromSubject = $page->subject;

            $page->update([
                'subject_id' => $subject->id,
                'chapter_id' => $chapter?->id,
                'sort_order' => $this->nextPosition($subject, $chapter?->id),
            ]);

            if ($fromSubject->id !== $subject->id) {
                StudyAttachment::query()->where('page_id', $page->id)->update(['subject_id' => $subject->id]);
                $fromSubject->touch();
            }

            $subject->touch();
        });
    }

    /**
     * Apply the order of the table of contents: chapters in order and, for
     * each page, its chapter and position. Ids from other subjects are ignored.
     *
     * @param  list<int>  $chapterIds
     * @param  list<array{id: int, chapter_id: int|null}>  $pages
     */
    public function arrange(StudySubject $subject, array $chapterIds, array $pages): void
    {
        DB::transaction(function () use ($subject, $chapterIds, $pages) {
            $chapters = $subject->chapters()->pluck('id')->flip();

            foreach ($chapterIds as $position => $id) {
                if (isset($chapters[$id])) {
                    $subject->chapters()->whereKey($id)->toBase()->update(['sort_order' => $position + 1]);
                }
            }

            $positions = [];

            foreach ($pages as $entry) {
                $chapterId = $entry['chapter_id'] !== null && isset($chapters[$entry['chapter_id']]) ? $entry['chapter_id'] : null;
                $key = $chapterId ?? 0;
                $positions[$key] = ($positions[$key] ?? 0) + 1;

                $subject->pages()->whereKey($entry['id'])->toBase()->update([
                    'chapter_id' => $chapterId,
                    'sort_order' => $positions[$key],
                ]);
            }

            $subject->touch();
        });
    }

    private function nextPosition(StudySubject $subject, ?int $chapterId): int
    {
        return (int) $subject->pages()->where('chapter_id', $chapterId)->max('sort_order') + 1;
    }
}
