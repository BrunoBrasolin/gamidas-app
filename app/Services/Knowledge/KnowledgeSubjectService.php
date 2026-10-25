<?php

namespace App\Services\Knowledge;

use App\Enums\KnowledgeColor;
use App\Models\KnowledgeAttachment;
use App\Models\KnowledgeSubject;
use App\Models\User;
use App\Support\KnowledgeUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Creates, edits, orders and deletes the subjects (books) of a user's knowledge
 * base, including the cover image.
 *
 * @phpstan-type SubjectData array{title: string, description: string|null, accent_color: KnowledgeColor}
 */
class KnowledgeSubjectService
{
    public function __construct(private readonly KnowledgeAttachmentService $attachments)
    {
        //
    }

    /**
     * New subjects go to the end of the manual order.
     *
     * @param  SubjectData  $data
     */
    public function create(User $user, array $data, ?UploadedFile $cover = null): KnowledgeSubject
    {
        $subject = $user->knowledgeSubjects()->create([
            ...$data,
            'sort_order' => (int) $user->knowledgeSubjects()->max('sort_order') + 1,
        ]);

        if ($cover !== null) {
            $this->replaceCover($subject, $cover);
        }

        return $subject;
    }

    /**
     * @param  SubjectData  $data
     */
    public function update(KnowledgeSubject $subject, array $data, ?UploadedFile $cover = null, bool $removeCover = false): KnowledgeSubject
    {
        $subject->update($data);

        if ($cover !== null) {
            $this->replaceCover($subject, $cover);
        } elseif ($removeCover) {
            $this->removeCover($subject);
        }

        return $subject;
    }

    public function replaceCover(KnowledgeSubject $subject, UploadedFile $file): void
    {
        $info = KnowledgeUpload::inspect($file);

        if ($info === null) {
            return;
        }

        $attachment = $this->attachments->store($subject, null, $file, $info);
        $previous = $subject->cover_attachment_id;

        $subject->update(['cover_attachment_id' => $attachment->id]);

        if ($previous !== null) {
            $this->attachments->delete(KnowledgeAttachment::query()->whereKey($previous)->get());
        }
    }

    public function removeCover(KnowledgeSubject $subject): void
    {
        $previous = $subject->cover_attachment_id;

        if ($previous === null) {
            return;
        }

        $subject->update(['cover_attachment_id' => null]);
        $this->attachments->delete(KnowledgeAttachment::query()->whereKey($previous)->get());
    }

    /**
     * Delete the subject with its categories, pages and every file.
     */
    public function delete(KnowledgeSubject $subject): void
    {
        DB::transaction(function () use ($subject) {
            $subject->update(['cover_attachment_id' => null]);
            $this->attachments->delete($subject->attachments()->get());
            $subject->delete();
        });
    }

    public function archive(KnowledgeSubject $subject): void
    {
        $subject->update(['archived_at' => now(), 'is_pinned' => false]);
    }

    public function restore(KnowledgeSubject $subject): void
    {
        $subject->update(['archived_at' => null]);
    }

    public function pin(KnowledgeSubject $subject, bool $pinned): void
    {
        // Pinning is not an edit of the book, so it does not bump updated_at.
        KnowledgeSubject::withoutTimestamps(fn () => $subject->update(['is_pinned' => $pinned]));
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
                $user->knowledgeSubjects()->whereKey($id)->toBase()->update(['sort_order' => $position + 1]);
            }
        });
    }

    public function markOpened(KnowledgeSubject $subject): void
    {
        KnowledgeSubject::withoutTimestamps(fn () => $subject->update(['last_opened_at' => now()]));
    }
}
