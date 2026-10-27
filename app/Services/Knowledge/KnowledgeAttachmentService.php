<?php

namespace App\Services\Knowledge;

use App\Enums\KnowledgeAttachmentKind;
use App\Models\KnowledgeAttachment;
use App\Models\KnowledgePage;
use App\Models\KnowledgeSubject;
use App\Support\KnowledgeContent;
use App\Support\KnowledgeUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Keeps attachment rows and the files on the private disk in step: files are
 * written before their row and removed only after the row is gone, so a
 * failure leaves at most an unreferenced file, which {@see self::prune()}
 * sweeps up.
 */
class KnowledgeAttachmentService
{
    public const DIRECTORY = 'knowledge';

    /**
     * Store an uploaded file. Page attachments start detached until the page
     * text that places them is saved; subject files are listed right away.
     *
     * @param  array{kind: KnowledgeAttachmentKind, mime: string, extension: string, width: int|null, height: int|null}  $info
     */
    public function store(KnowledgeSubject $subject, ?KnowledgePage $page, UploadedFile $file, array $info, bool $subjectFile = false): KnowledgeAttachment
    {
        $disk = (string) config('knowledge.disk');
        $path = self::DIRECTORY.'/'.$subject->user_id.'/'.Str::ulid()->toBase32().'.'.$info['extension'];

        $stored = Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));

        if ($stored === false) {
            throw new \RuntimeException('Could not store the knowledge attachment.');
        }

        try {
            return KnowledgeAttachment::query()->create([
                'subject_id' => $subject->id,
                'page_id' => $page?->id,
                'is_subject_file' => $page === null && $subjectFile,
                'kind' => $info['kind'],
                'filename' => KnowledgeUpload::displayName($file->getClientOriginalName(), $info['extension']),
                'mime_type' => $info['mime'],
                'size' => (int) $file->getSize(),
                'disk' => $disk,
                'path' => $path,
                'width' => $info['width'],
                'height' => $info['height'],
                'detached_at' => $page === null ? null : now(),
            ]);
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }

    /**
     * Rename a file, keeping its extension.
     */
    public function rename(KnowledgeAttachment $attachment, string $name): void
    {
        $attachment->update(['filename' => KnowledgeUpload::displayName($name, pathinfo($attachment->path, PATHINFO_EXTENSION))]);
    }

    /**
     * Remember where the built-in reader was left in a paged file.
     */
    public function recordReading(KnowledgeAttachment $attachment, int $page, int $pages): void
    {
        KnowledgeAttachment::withoutTimestamps(fn () => $attachment->update([
            'reading_page' => max(1, min($page, $pages)),
            'page_count' => $pages,
        ]));
    }

    /**
     * Copy an attachment (file included) onto another page.
     */
    public function duplicate(KnowledgeAttachment $attachment, KnowledgePage $page): KnowledgeAttachment
    {
        $path = self::DIRECTORY.'/'.$page->subject->user_id.'/'.Str::ulid()->toBase32().'.'.pathinfo($attachment->path, PATHINFO_EXTENSION);

        if (! Storage::disk($attachment->disk)->copy($attachment->path, $path)) {
            throw new \RuntimeException('Could not copy the knowledge attachment.');
        }

        return KnowledgeAttachment::query()->create([
            ...$attachment->only(['kind', 'filename', 'mime_type', 'size', 'disk', 'width', 'height', 'sort_order']),
            'subject_id' => $page->subject_id,
            'page_id' => $page->id,
            'path' => $path,
            'detached_at' => null,
        ]);
    }

    /**
     * After the page text is saved: attachments placed in it are kept in
     * reading order, the others are marked detached (and pruned later).
     *
     * @param  list<int>  $placedIds
     */
    public function syncPage(KnowledgePage $page, array $placedIds): void
    {
        $order = array_flip($placedIds);

        foreach ($page->attachments()->get() as $attachment) {
            if (isset($order[$attachment->id])) {
                $attachment->fill(['sort_order' => $order[$attachment->id], 'detached_at' => null]);
            } elseif ($attachment->detached_at === null) {
                $attachment->detached_at = now();
            }

            $attachment->save();
        }
    }

    /**
     * Delete attachments and then their files.
     *
     * @param  Collection<int, KnowledgeAttachment>  $attachments
     */
    public function delete(Collection $attachments): void
    {
        if ($attachments->isEmpty()) {
            return;
        }

        $files = $attachments->map(fn (KnowledgeAttachment $attachment) => [$attachment->disk, $attachment->path])->values()->all();

        KnowledgeAttachment::query()->whereKey($attachments->map(fn (KnowledgeAttachment $attachment) => $attachment->id)->all())->delete();

        $this->deleteFilesAfterCommit($files);
    }

    /**
     * Remove files once the surrounding transaction (if any) commits, so a
     * rolled-back deletion never loses a file still referenced by a row.
     *
     * @param  array<int, array{0: string, 1: string}>  $files
     */
    public function deleteFilesAfterCommit(array $files): void
    {
        DB::afterCommit(function () use ($files) {
            foreach ($files as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }
        });
    }

    /**
     * Delete attachments detached for longer than the grace period and files
     * on the knowledge disk that no row points to. Returns how many files went.
     */
    public function prune(): int
    {
        $cutoff = now()->subHours((int) config('knowledge.prune_after_hours'));
        $count = 0;

        KnowledgeAttachment::query()
            ->whereNotNull('detached_at')
            ->where('detached_at', '<', $cutoff)
            ->with('page:id,content')
            ->chunkById(200, function (Collection $attachments) use (&$count) {
                // Never prune something the page still places (defensive: the
                // flag is refreshed on every save).
                $stale = $attachments->reject(fn (KnowledgeAttachment $attachment) => $attachment->page !== null
                    && in_array($attachment->id, KnowledgeContent::attachmentIds($attachment->page->content), true));

                $this->delete($stale);
                $count += $stale->count();
            });

        $disk = Storage::disk((string) config('knowledge.disk'));
        $known = KnowledgeAttachment::query()->pluck('path')->flip();

        foreach ($disk->allFiles(self::DIRECTORY) as $path) {
            if (! isset($known[$path]) && $disk->lastModified($path) < $cutoff->getTimestamp()) {
                $disk->delete($path);
                $count++;
            }
        }

        return $count;
    }
}
