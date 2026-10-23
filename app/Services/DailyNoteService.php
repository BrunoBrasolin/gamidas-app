<?php

namespace App\Services;

use App\Models\DailyNote;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Saves daily Markdown notes with optimistic concurrency: each save names the
 * revision it was based on, and a save based on an outdated revision (the
 * note changed in another tab) is refused instead of overwriting it.
 */
class DailyNoteService
{
    public function find(User $user, CarbonImmutable $date): ?DailyNote
    {
        return DailyNote::query()
            ->where('user_id', $user->id)
            ->where('note_date', $date->toDateString())
            ->first();
    }

    /**
     * Save the note of a day. Blank content removes the note.
     *
     * @return array{saved: bool, note: ?DailyNote}
     */
    public function save(User $user, CarbonImmutable $date, string $content, int $baseRevision): array
    {
        try {
            return DB::transaction(function () use ($user, $date, $content, $baseRevision) {
                $note = DailyNote::query()
                    ->where('user_id', $user->id)
                    ->where('note_date', $date->toDateString())
                    ->lockForUpdate()
                    ->first();

                if (($note->revision ?? 0) !== $baseRevision) {
                    return ['saved' => false, 'note' => $note];
                }

                if (trim($content) === '') {
                    $note?->delete();

                    return ['saved' => true, 'note' => null];
                }

                if ($note === null) {
                    $note = $user->dailyNotes()->create([
                        'note_date' => $date,
                        'content' => $content,
                        'revision' => 1,
                    ]);
                } else {
                    $note->update(['content' => $content, 'revision' => $note->revision + 1]);
                }

                return ['saved' => true, 'note' => $note];
            });
        } catch (UniqueConstraintViolationException) {
            // Another request created the note of this day at the same time.
            return ['saved' => false, 'note' => $this->find($user, $date)];
        }
    }

    /**
     * @return array{date: string, content: string, revision: int, updated_at: ?string}
     */
    public static function toPage(CarbonImmutable $date, ?DailyNote $note): array
    {
        return [
            'date' => $date->toDateString(),
            'content' => $note->content ?? '',
            'revision' => $note->revision ?? 0,
            'updated_at' => $note?->updated_at?->toIso8601String(),
        ];
    }
}
