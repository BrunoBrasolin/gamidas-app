<?php

namespace App\Services;

use App\Models\ComplementaryActivity;
use App\Models\User;
use App\Support\KnowledgeUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Keeps complementary activities and their certificate files in step: a new
 * file is written before its row, and an old one is removed only after the
 * row stopped pointing to it and the transaction committed.
 *
 * @phpstan-type ActivityData array{title: string, completed_on: string|null, minutes: int, report: string|null}
 * @phpstan-type CertificateInfo array{mime: string, extension: string}
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
     * @param  CertificateInfo  $info
     */
    public function create(User $user, array $data, UploadedFile $certificate, array $info): ComplementaryActivity
    {
        $file = $this->storeCertificate($user, $certificate, $info);

        try {
            return $user->complementaryActivities()->create([...$data, ...$file]);
        } catch (Throwable $exception) {
            Storage::disk($file['certificate_disk'])->delete($file['certificate_path']);

            throw $exception;
        }
    }

    /**
     * Update an activity, replacing its certificate when a new one is sent.
     *
     * @param  ActivityData  $data
     * @param  CertificateInfo|null  $info
     */
    public function update(ComplementaryActivity $activity, array $data, ?UploadedFile $certificate = null, ?array $info = null): ComplementaryActivity
    {
        if ($certificate === null || $info === null) {
            $activity->update($data);

            return $activity;
        }

        $old = [$activity->certificate_disk, $activity->certificate_path];
        $file = $this->storeCertificate($activity->user, $certificate, $info);

        try {
            $activity->update([...$data, ...$file]);
        } catch (Throwable $exception) {
            Storage::disk($file['certificate_disk'])->delete($file['certificate_path']);

            throw $exception;
        }

        $this->deleteFileAfterCommit(...$old);

        return $activity;
    }

    public function delete(ComplementaryActivity $activity): void
    {
        $activity->delete();

        $this->deleteFileAfterCommit($activity->certificate_disk, $activity->certificate_path);
    }

    /**
     * Hours required, completed and still missing, in minutes.
     *
     * @return array{required_minutes: int|null, done_minutes: int, remaining_minutes: int|null, activities: int, without_report: int}
     */
    public function summary(User $user): array
    {
        $required = $user->complementaryHoursGoal?->required_minutes;
        $activities = $user->complementaryActivities()->get(['minutes', 'report']);
        $done = (int) $activities->sum('minutes');

        return [
            'required_minutes' => $required,
            'done_minutes' => $done,
            'remaining_minutes' => $required === null ? null : max(0, $required - $done),
            'activities' => $activities->count(),
            'without_report' => $activities->reject(fn (ComplementaryActivity $activity) => $activity->hasReport())->count(),
        ];
    }

    /**
     * @param  CertificateInfo  $info
     * @return array{certificate_disk: string, certificate_path: string, certificate_filename: string, certificate_mime: string, certificate_size: int}
     */
    private function storeCertificate(User $user, UploadedFile $certificate, array $info): array
    {
        $disk = (string) config('complementary_hours.disk');
        $path = self::DIRECTORY.'/'.$user->id.'/'.Str::ulid()->toBase32().'.'.$info['extension'];

        if (Storage::disk($disk)->putFileAs(dirname($path), $certificate, basename($path)) === false) {
            throw new \RuntimeException('Could not store the certificate.');
        }

        return [
            'certificate_disk' => $disk,
            'certificate_path' => $path,
            'certificate_filename' => KnowledgeUpload::displayName($certificate->getClientOriginalName(), $info['extension']),
            'certificate_mime' => $info['mime'],
            'certificate_size' => (int) $certificate->getSize(),
        ];
    }

    private function deleteFileAfterCommit(string $disk, string $path): void
    {
        DB::afterCommit(fn () => Storage::disk($disk)->delete($path));
    }
}
