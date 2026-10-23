<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes manual time entries, the only source of recorded time. Entries are
 * created and changed exclusively by explicit user actions; timer sessions
 * never write here.
 */
class TimeEntryService
{
    /**
     * How far back "copy from the previous day" looks for a day with entries.
     */
    public const COPY_LOOKBACK_DAYS = 60;

    /**
     * @param  array{task_id: int, entry_date: CarbonImmutable, duration_minutes: int, description: ?string}  $data
     */
    public function create(User $user, array $data): TimeEntry
    {
        return $user->timeEntries()->create($data);
    }

    /**
     * @param  array{task_id: int, entry_date: CarbonImmutable, duration_minutes: int, description: ?string}  $data
     */
    public function update(TimeEntry $entry, array $data): TimeEntry
    {
        $entry->update($data);

        return $entry;
    }

    /**
     * Set the time of a sheet cell (task × date) typed inline: an empty cell
     * gets a new entry, a cell with one entry has it changed (or removed when
     * set to zero). Cells with several entries are edited entry by entry.
     */
    public function setCell(User $user, Task $task, CarbonImmutable $date, int $minutes): ?TimeEntry
    {
        return DB::transaction(function () use ($user, $task, $date, $minutes) {
            $entries = TimeEntry::query()
                ->where('user_id', $user->id)
                ->where('task_id', $task->id)
                ->where('entry_date', $date->toDateString())
                ->lockForUpdate()
                ->get();

            if ($entries->count() > 1) {
                throw ValidationException::withMessages([
                    'minutes' => 'Esta célula tem vários lançamentos; edite-os individualmente.',
                ]);
            }

            $entry = $entries->first();

            if ($entry === null) {
                if ($minutes === 0) {
                    return null;
                }

                if ($task->isArchived()) {
                    throw ValidationException::withMessages(['task_id' => 'Não é possível lançar tempo em uma tarefa arquivada.']);
                }

                return $this->create($user, [
                    'task_id' => $task->id,
                    'entry_date' => $date,
                    'duration_minutes' => $minutes,
                    'description' => null,
                ]);
            }

            if ($minutes === 0) {
                $entry->delete();

                return null;
            }

            $entry->update(['duration_minutes' => $minutes]);

            return $entry;
        });
    }

    /**
     * The most recent day before $date, within the look-back window, that has
     * entries for tasks that are not archived.
     *
     * @return array{date: string, count: int}|null
     */
    public function copySource(User $user, CarbonImmutable $date): ?array
    {
        $source = $this->copyableQuery($user)
            ->where('entry_date', '<', $date->toDateString())
            ->where('entry_date', '>=', $date->subDays(self::COPY_LOOKBACK_DAYS)->toDateString())
            ->max('entry_date');

        if ($source === null) {
            return null;
        }

        $source = substr((string) $source, 0, 10);

        return [
            'date' => $source,
            'count' => $this->copyableQuery($user)->where('entry_date', $source)->count(),
        ];
    }

    /**
     * Copy the entries of one day to another. Entries already present on the
     * target day (same task, duration and description) are not duplicated, so
     * repeating the action is harmless. Returns how many entries were created.
     */
    public function copyDay(User $user, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return DB::transaction(function () use ($user, $from, $to) {
            $existing = TimeEntry::query()
                ->where('user_id', $user->id)
                ->where('entry_date', $to->toDateString())
                ->lockForUpdate()
                ->get()
                ->map(fn (TimeEntry $entry) => $this->signature($entry))
                ->countBy()
                ->all();

            $created = 0;

            $sources = $this->copyableQuery($user)->where('entry_date', $from->toDateString())->orderBy('id')->get();

            foreach ($sources as $source) {
                $signature = $this->signature($source);

                if (($existing[$signature] ?? 0) > 0) {
                    $existing[$signature]--;

                    continue;
                }

                $this->create($user, [
                    'task_id' => $source->task_id,
                    'entry_date' => $to,
                    'duration_minutes' => $source->duration_minutes,
                    'description' => $source->description,
                ]);
                $created++;
            }

            return $created;
        });
    }

    /**
     * @return Builder<TimeEntry>
     */
    private function copyableQuery(User $user): Builder
    {
        return TimeEntry::query()
            ->where('user_id', $user->id)
            ->whereHas('task', fn ($query) => $query->whereNull('archived_at'));
    }

    private function signature(TimeEntry $entry): string
    {
        return $entry->task_id.'|'.$entry->duration_minutes.'|'.($entry->description ?? '');
    }
}
