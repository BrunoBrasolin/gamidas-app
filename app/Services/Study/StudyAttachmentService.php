<?php

namespace App\Services\Study;

use App\Enums\StudyAttachmentKind;
use App\Models\StudyAttachment;
use App\Models\StudyPage;
use App\Models\StudySubject;
use App\Support\StudyContent;
use App\Support\StudyUpload;
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
class StudyAttachmentService
{
    public const DIRECTORY = 'study';

    /**
     * Store an uploaded file. Page attachments start detached until the page
     * text that places them is saved.
     *
     * @param  array{kind: StudyAttachmentKind, mime: string, extension: string, width: int|null, height: int|null}  $info
     */
    public function store(StudySubject $subject, ?StudyPage $page, UploadedFile $file, array $info): StudyAttachment
    {
        $disk = (string) config('study.disk');
        $path = self::DIRECTORY.'/'.$subject->user_id.'/'.Str::ulid()->toBase32().'.'.$info['extension'];

        $stored = Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));

        if ($stored === false) {
            throw new \RuntimeException('Could not store the study attachment.');
        }

        try {
            return StudyAttachment::query()->create([
                'subject_id' => $subject->id,
                'page_id' => $page?->id,
                'kind' => $info['kind'],
                'filename' => StudyUpload::displayName($file->getClientOriginalName(), $info['extension']),
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
     * Copy an attachment (file included) onto another page.
     */
    public function duplicate(StudyAttachment $attachment, StudyPage $page): StudyAttachment
    {
        $path = self::DIRECTORY.'/'.$page->subject->user_id.'/'.Str::ulid()->toBase32().'.'.pathinfo($attachment->path, PATHINFO_EXTENSION);

        if (! Storage::disk($attachment->disk)->copy($attachment->path, $path)) {
            throw new \RuntimeException('Could not copy the study attachment.');
        }

        return StudyAttachment::query()->create([
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
    public function syncPage(StudyPage $page, array $placedIds): void
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
     * @param  Collection<int, StudyAttachment>  $attachments
     */
    public function delete(Collection $attachments): void
    {
        if ($attachments->isEmpty()) {
            return;
        }

        $files = $attachments->map(fn (StudyAttachment $attachment) => [$attachment->disk, $attachment->path])->values()->all();

        StudyAttachment::query()->whereKey($attachments->map(fn (StudyAttachment $attachment) => $attachment->id)->all())->delete();

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
     * on the study disk that no row points to. Returns how many files went.
     */
    public function prune(): int
    {
        $cutoff = now()->subHours((int) config('study.prune_after_hours'));
        $count = 0;

        StudyAttachment::query()
            ->whereNotNull('detached_at')
            ->where('detached_at', '<', $cutoff)
            ->with('page:id,content')
            ->chunkById(200, function (Collection $attachments) use (&$count) {
                // Never prune something the page still places (defensive: the
                // flag is refreshed on every save).
                $stale = $attachments->reject(fn (StudyAttachment $attachment) => $attachment->page !== null
                    && in_array($attachment->id, StudyContent::attachmentIds($attachment->page->content), true));

                $this->delete($stale);
                $count += $stale->count();
            });

        $disk = Storage::disk((string) config('study.disk'));
        $known = StudyAttachment::query()->pluck('path')->flip();

        foreach ($disk->allFiles(self::DIRECTORY) as $path) {
            if (! isset($known[$path]) && $disk->lastModified($path) < $cutoff->getTimestamp()) {
                $disk->delete($path);
                $count++;
            }
        }

        return $count;
    }
}
