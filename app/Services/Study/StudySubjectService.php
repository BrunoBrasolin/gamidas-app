<?php

namespace App\Services\Study;

use App\Enums\StudyColor;
use App\Models\StudyAttachment;
use App\Models\StudySubject;
use App\Models\User;
use App\Support\StudyUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Creates, edits, orders and deletes the subjects (books) of a user's study
 * library, including the cover image.
 *
 * @phpstan-type SubjectData array{title: string, description: string|null, accent_color: StudyColor}
 */
class StudySubjectService
{
    public function __construct(private readonly StudyAttachmentService $attachments)
    {
        //
    }

    /**
     * New subjects go to the end of the manual order.
     *
     * @param  SubjectData  $data
     */
    public function create(User $user, array $data, ?UploadedFile $cover = null): StudySubject
    {
        $subject = $user->studySubjects()->create([
            ...$data,
            'sort_order' => (int) $user->studySubjects()->max('sort_order') + 1,
        ]);

        if ($cover !== null) {
            $this->replaceCover($subject, $cover);
        }

        return $subject;
    }

    /**
     * @param  SubjectData  $data
     */
    public function update(StudySubject $subject, array $data, ?UploadedFile $cover = null, bool $removeCover = false): StudySubject
    {
        $subject->update($data);

        if ($cover !== null) {
            $this->replaceCover($subject, $cover);
        } elseif ($removeCover) {
            $this->removeCover($subject);
        }

        return $subject;
    }

    public function replaceCover(StudySubject $subject, UploadedFile $file): void
    {
        $info = StudyUpload::inspect($file);

        if ($info === null) {
            return;
        }

        $attachment = $this->attachments->store($subject, null, $file, $info);
        $previous = $subject->cover_attachment_id;

        $subject->update(['cover_attachment_id' => $attachment->id]);

        if ($previous !== null) {
            $this->attachments->delete(StudyAttachment::query()->whereKey($previous)->get());
        }
    }

    public function removeCover(StudySubject $subject): void
    {
        $previous = $subject->cover_attachment_id;

        if ($previous === null) {
            return;
        }

        $subject->update(['cover_attachment_id' => null]);
        $this->attachments->delete(StudyAttachment::query()->whereKey($previous)->get());
    }

    /**
     * Delete the subject with its chapters, pages and every file.
     */
    public function delete(StudySubject $subject): void
    {
        DB::transaction(function () use ($subject) {
            $subject->update(['cover_attachment_id' => null]);
            $this->attachments->delete($subject->attachments()->get());
            $subject->delete();
        });
    }

    public function archive(StudySubject $subject): void
    {
        $subject->update(['archived_at' => now(), 'is_pinned' => false]);
    }

    public function restore(StudySubject $subject): void
    {
        $subject->update(['archived_at' => null]);
    }

    public function pin(StudySubject $subject, bool $pinned): void
    {
        // Pinning is not an edit of the book, so it does not bump updated_at.
        StudySubject::withoutTimestamps(fn () => $subject->update(['is_pinned' => $pinned]));
    }

    /**
     * Save the manual order. Ids not owned by the user are ignored.
     *
     * @param  list<int>  $ids
     */
    public function reorder(User $user, array $ids): void
    {
        DB::transaction(function () use ($user, $ids) {
            foreach ($ids as $position => $id) {
                $user->studySubjects()->whereKey($id)->toBase()->update(['sort_order' => $position + 1]);
            }
        });
    }

    public function markOpened(StudySubject $subject): void
    {
        StudySubject::withoutTimestamps(fn () => $subject->update(['last_opened_at' => now()]));
    }
}
