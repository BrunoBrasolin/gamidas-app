<?php

namespace App\Services;

use App\Enums\ComplementaryAttachmentKind;
use App\Models\ComplementaryActivity;
use App\Models\ComplementaryActivityAttachment;
use App\Models\User;
use App\Support\KnowledgeUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Keeps complementary activities and their attachment files in step: new
 * files are written before their rows, and old ones are removed only after
 * no row points to them and the transaction committed.
 *
 * @phpstan-type ActivityData array{title: string, completed_on: string|null, minutes: int, report_done: bool}
 * @phpstan-type Upload array{kind: ComplementaryAttachmentKind, file: UploadedFile, mime: string, extension: string}
 * @phpstan-type StoredFile array{kind: ComplementaryAttachmentKind, disk: string, path: string, filename: string, mime: string, size: int}
 */
class ComplementaryHoursService
{
    public const DIRECTORY = 'complementary-hours';

    public function setGoal(User $user, int $requiredMinutes): void
    {
        $user->complementaryHoursGoal()->updateOrCreate([], ['required_minutes' => $requiredMinutes]);
    }

    /**
     * @param  ActivityData  $data
     * @param  list<Upload>  $uploads
     */
    public function create(User $user, array $data, array $uploads): ComplementaryActivity
    {
        $files = $this->storeAll($user, $uploads);

        try {
            return DB::transaction(function () use ($user, $data, $files) {
                $activity = $user->complementaryActivities()->create($data);
                $activity->attachments()->createMany($files);

                return $activity;
            });
        } catch (Throwable $exception) {
            $this->deleteNow($files);

            throw $exception;
        }
    }

    /**
     * Update an activity, adding the files sent and removing the attachments
     * listed (only the activity's own ones).
     *
     * @param  ActivityData  $data
     * @param  list<Upload>  $uploads
     * @param  list<int>  $removeIds
     */
    public function update(ComplementaryActivity $activity, array $data, array $uploads = [], array $removeIds = []): ComplementaryActivity
    {
        $files = $this->storeAll($activity->user, $uploads);

        try {
            DB::transaction(function () use ($activity, $data, $files, $removeIds) {
                $activity->update($data);
                $activity->attachments()->createMany($files);

                $removed = $activity->attachments()->whereIn('id', $removeIds)->get();
                $activity->attachments()->whereKey($removed->modelKeys())->delete();

                $removed->each(fn (ComplementaryActivityAttachment $attachment) => $this->deleteFileAfterCommit($attachment->disk, $attachment->path));
            });
        } catch (Throwable $exception) {
            $this->deleteNow($files);

            throw $exception;
        }

        return $activity;
    }

    public function delete(ComplementaryActivity $activity): void
    {
        DB::transaction(function () use ($activity) {
            $attachments = $activity->attachments()->get();
            $activity->delete();

            $attachments->each(fn (ComplementaryActivityAttachment $attachment) => $this->deleteFileAfterCommit($attachment->disk, $attachment->path));
        });
    }

    /**
     * Hours required, completed and still missing, in minutes.
     *
     * @return array{required_minutes: int|null, done_minutes: int, remaining_minutes: int|null, activities: int, without_report: int}
     */
    public function summary(User $user): array
    {
        $required = $user->complementaryHoursGoal?->required_minutes;
        $activities = $user->complementaryActivities()->get(['minutes', 'report_done']);
        $done = (int) $activities->sum('minutes');

        return [
            'required_minutes' => $required,
            'done_minutes' => $done,
            'remaining_minutes' => $required === null ? null : max(0, $required - $done),
            'activities' => $activities->count(),
            'without_report' => $activities->where('report_done', false)->count(),
        ];
    }

    /**
     * @param  list<Upload>  $uploads
     * @return list<StoredFile>
     */
    private function storeAll(User $user, array $uploads): array
    {
        $files = [];

        try {
            foreach ($uploads as $upload) {
                $files[] = $this->store($user, $upload);
            }
        } catch (Throwable $exception) {
            $this->deleteNow($files);

            throw $exception;
        }

        return $files;
    }

    /**
     * @param  Upload  $upload
     * @return StoredFile
     */
    private function store(User $user, array $upload): array
    {
        $disk = (string) config('complementary_hours.disk');
        $path = self::DIRECTORY.'/'.$user->id.'/'.Str::ulid()->toBase32().'.'.$upload['extension'];

        if (Storage::disk($disk)->putFileAs(dirname($path), $upload['file'], basename($path)) === false) {
            throw new \RuntimeException('Could not store the attachment.');
        }

        return [
            'kind' => $upload['kind'],
            'disk' => $disk,
            'path' => $path,
            'filename' => KnowledgeUpload::displayName($upload['file']->getClientOriginalName(), $upload['extension']),
            'mime' => $upload['mime'],
            'size' => (int) $upload['file']->getSize(),
        ];
    }

    /**
     * @param  list<StoredFile>  $files
     */
    private function deleteNow(array $files): void
    {
        foreach ($files as $file) {
            Storage::disk($file['disk'])->delete($file['path']);
        }
    }

    private function deleteFileAfterCommit(string $disk, string $path): void
    {
        DB::afterCommit(fn () => Storage::disk($disk)->delete($path));
    }
}
