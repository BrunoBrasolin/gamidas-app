<?php

namespace App\Services;

use App\Models\TimeCategory;
use App\Models\TimeLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes the cells of the time sheet: the time and note of a category on a
 * date. A cell left with neither time nor note is removed.
 */
class TimeLogService
{
    /**
     * Save a cell. A null $note keeps the stored note, so editing only the
     * time never erases it.
     */
    public function save(TimeCategory $category, CarbonImmutable $date, int $seconds, ?string $note, bool $replaceNote): ?TimeLog
    {
        return DB::transaction(function () use ($category, $date, $seconds, $note, $replaceNote) {
            $log = TimeLog::query()
                ->where('time_category_id', $category->id)
                ->where('log_date', $date->toDateString())
                ->lockForUpdate()
                ->first();

            if ($category->isArchived() && $log === null) {
                throw ValidationException::withMessages([
                    'category' => 'Categoria arquivada não recebe novos lançamentos.',
                ]);
            }

            $note = $replaceNote ? $this->cleanNote($note) : $log?->note;

            if ($seconds === 0 && $note === null) {
                $log?->delete();

                return null;
            }

            $log ??= new TimeLog([
                'user_id' => $category->user_id,
                'time_category_id' => $category->id,
                'log_date' => $date,
            ]);
            $log->fill(['seconds' => $seconds, 'note' => $note])->save();

            return $log;
        });
    }

    private function cleanNote(?string $note): ?string
    {
        $note = rtrim((string) $note);

        return trim($note) === '' ? null : $note;
    }
}
