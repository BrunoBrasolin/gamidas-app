<?php

namespace App\Services;

use App\Models\TimeCategory;
use App\Models\TimeLog;
use App\Models\TimeTimer;
use App\Models\User;
use App\Support\Day;
use App\Support\Holidays;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Read-side aggregations of the time-tracking module: the time sheet and the
 * reports. Every total is a sum of the seconds recorded in the sheet cells.
 */
class TimeSummary
{
    /**
     * Seconds per day ("Y-m-d" => seconds), only for days with time.
     *
     * @return array<string, int>
     */
    public function secondsByDay(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $categoryId = null): array
    {
        return $this->logs($user, $from, $to, $categoryId)
            ->where('seconds', '>', 0)
            ->groupBy('log_date')
            ->select('log_date', DB::raw('sum(seconds) as seconds'))
            ->get()
            ->mapWithKeys(fn (TimeLog $row) => [$row->log_date->toDateString() => (int) $row->getAttribute('seconds')])
            ->all();
    }

    /**
     * Seconds per category (category id => seconds) in a period.
     *
     * @return array<int, int>
     */
    public function secondsByCategory(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $categoryId = null): array
    {
        return array_filter($this->logs($user, $from, $to, $categoryId)
            ->groupBy('time_category_id')
            ->select('time_category_id', DB::raw('sum(seconds) as seconds'))
            ->pluck('seconds', 'time_category_id')
            ->map(fn ($seconds) => (int) $seconds)
            ->all());
    }

    public function total(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $categoryId = null): int
    {
        return (int) $this->logs($user, $from, $to, $categoryId)->sum('seconds');
    }

    /**
     * Every category of the user for selects and filters, archived ones last.
     *
     * @return list<array{id: int, name: string, archived: bool}>
     */
    public function categoryOptions(User $user): array
    {
        return array_values($user->timeCategories()
            ->orderByRaw('archived_at is not null')
            ->orderBy('name')
            ->get()
            ->map(fn (TimeCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'archived' => $category->isArchived(),
            ])
            ->all());
    }

    /**
     * The stopwatch of every active category (plus archived ones that still
     * have one), with the server clock so the browser can count from it.
     *
     * @return array{now: int, items: list<array<string, mixed>>}
     */
    public function timers(User $user): array
    {
        $categories = $user->timeCategories()
            ->where(fn ($query) => $query->whereNull('archived_at')->orWhereHas('timer'))
            ->orderBy('name')
            ->get();

        $timers = TimeTimer::query()->where('user_id', $user->id)->get()->keyBy('time_category_id');

        return [
            'now' => Date::now()->getTimestampMs(),
            'items' => array_values($categories->map(function (TimeCategory $category) use ($timers) {
                $timer = $timers->get($category->id);

                return [
                    'category_id' => $category->id,
                    'name' => $category->name,
                    'archived' => $category->isArchived(),
                    'accumulated_seconds' => $timer === null ? 0 : $timer->accumulated_seconds,
                    'started_at' => $timer?->started_at?->getTimestampMs(),
                ];
            })->all()),
        ];
    }

    /**
     * The time sheet of a period: one row per date, one column per category.
     *
     * Columns are the active categories plus archived ones that have a cell
     * in the period, so history stays visible.
     *
     * @return array<string, mixed>
     */
    public function sheet(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $categoryId, string $sort): array
    {
        $logs = $this->logs($user, $from, $to, $categoryId)
            ->get(['id', 'time_category_id', 'log_date', 'seconds', 'note']);

        $columns = $user->timeCategories()
            ->when($categoryId !== null, fn (Builder $query) => $query->whereKey($categoryId))
            ->where(fn (Builder $query) => $query
                ->whereNull('archived_at')
                ->orWhereIn('id', $logs->pluck('time_category_id')->unique()->all()))
            ->orderBy('name')
            ->get();

        $cells = [];
        $byDay = [];
        $byCategory = [];

        foreach ($logs as $log) {
            $date = $log->log_date->toDateString();
            $cells[$date][$log->time_category_id] = ['seconds' => $log->seconds, 'note' => $log->note];
            $byDay[$date] = ($byDay[$date] ?? 0) + $log->seconds;
            $byCategory[$log->time_category_id] = ($byCategory[$log->time_category_id] ?? 0) + $log->seconds;
        }

        if ($sort === 'total') {
            $columns = $columns->sortByDesc(fn (TimeCategory $category) => $byCategory[$category->id] ?? 0)->values();
        }

        $today = Day::today()->toDateString();

        return [
            'days' => array_map(fn (string $date) => [
                'date' => $date,
                'is_today' => $date === $today,
                'total_seconds' => $byDay[$date] ?? 0,
            ], Day::range($from, $to)),
            'categories' => array_values($columns->map(fn (TimeCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'archived' => $category->isArchived(),
                'daily_goal_seconds' => $category->daily_goal_seconds,
                'total_seconds' => $byCategory[$category->id] ?? 0,
            ])->all()),
            'cells' => (object) $cells,
            'total_seconds' => array_sum($byDay),
        ];
    }

    /**
     * The daily goal judged day by day over a period.
     *
     * Only business days (Monday to Friday, except national holidays) have a
     * verdict. A category's goal applies from the day it was created (or any
     * earlier day with time in it) until the day it was archived, using its
     * current goal. A business day meets the goal when every applicable
     * category reaches its own goal; today is "pending" until it does, and
     * future days have no verdict. Weekends and holidays with time recorded
     * are reported apart, as days worked off the clock.
     *
     * @return array{summary: array<string, mixed>, days: list<array<string, mixed>>}
     */
    public function goals(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $categoryId = null): array
    {
        $today = Day::today()->toDateString();

        $cells = [];

        foreach ($this->logs($user, $from, $to, $categoryId)->where('seconds', '>', 0)->get(['time_category_id', 'log_date', 'seconds']) as $log) {
            $cells[$log->log_date->toDateString()][$log->time_category_id] = $log->seconds;
        }

        $categories = $user->timeCategories()
            ->when($categoryId !== null, fn (Builder $query) => $query->whereKey($categoryId))
            ->orderBy('name')
            ->get();

        $withGoal = $categories->filter(fn (TimeCategory $category) => $category->daily_goal_seconds !== null)->keyBy('id');
        $names = $categories->pluck('name', 'id');

        $days = [];

        foreach (Day::range($from, $to) as $date) {
            $tracked = $cells[$date] ?? [];
            $holiday = Holidays::name($date);
            $business = Holidays::isBusinessDay($date);

            $goals = $business ? $withGoal->filter(fn (TimeCategory $category) => ($category->created_at === null
                    || $category->created_at->toDateString() <= $date
                    || isset($tracked[$category->id]))
                && ($category->archived_at === null || $category->archived_at->toDateString() > $date)) : collect();

            $met = $goals->every(fn (TimeCategory $category) => ($tracked[$category->id] ?? 0) >= $category->daily_goal_seconds);
            $seconds = array_sum($tracked);

            $status = match (true) {
                ! $business => $seconds > 0 ? 'off_worked' : 'off',
                $goals->isEmpty() => 'no_goal',
                $date > $today => 'future',
                $met => 'met',
                $date === $today => 'pending',
                default => 'missed',
            };

            $rows = [];

            foreach ($names as $id => $name) {
                $goal = $goals->get($id)?->daily_goal_seconds;

                if (isset($tracked[$id]) || $goal !== null) {
                    $rows[] = ['category_id' => $id, 'name' => $name, 'seconds' => $tracked[$id] ?? 0, 'goal_seconds' => $goal];
                }
            }

            $days[] = [
                'date' => $date,
                'seconds' => $seconds,
                'goal_seconds' => $goals->isEmpty() ? null : (int) $goals->sum('daily_goal_seconds'),
                'shortfall_seconds' => (int) $goals->sum(fn (TimeCategory $category) => max(0, $category->daily_goal_seconds - ($tracked[$category->id] ?? 0))),
                'business' => $business,
                'holiday' => $holiday,
                'status' => $status,
                'categories' => $rows,
            ];
        }

        return ['summary' => $this->goalSummary($days, $withGoal->isNotEmpty()), 'days' => $days];
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @return array<string, mixed>
     */
    private function goalSummary(array $days, bool $hasGoal): array
    {
        $count = fn (string $status) => count(array_filter($days, fn (array $day) => $day['status'] === $status));
        $met = $count('met');
        $missed = $count('missed');

        $streak = 0;
        $best = 0;

        // Only judged days count: a pending today neither extends nor breaks it.
        foreach ($days as $day) {
            if ($day['status'] === 'met') {
                $best = max($best, ++$streak);
            } elseif ($day['status'] === 'missed') {
                $streak = 0;
            }
        }

        $offWorked = array_filter($days, fn (array $day) => $day['status'] === 'off_worked');

        return [
            'has_goal' => $hasGoal,
            'business_days' => count(array_filter($days, fn (array $day) => $day['business'])),
            'met' => $met,
            'missed' => $missed,
            'pending' => $count('pending'),
            'future' => $count('future'),
            // Basis points of the judged business days (8000 = 80%).
            'rate' => $met + $missed > 0 ? intdiv($met * 10_000, $met + $missed) : null,
            'shortfall_seconds' => array_sum(array_map(
                fn (array $day) => $day['status'] === 'missed' ? $day['shortfall_seconds'] : 0,
                $days,
            )),
            'current_streak' => $streak,
            'best_streak' => $best,
            'off_days_worked' => count($offWorked),
            'off_seconds' => array_sum(array_column($offWorked, 'seconds')),
        ];
    }

    /**
     * Seconds for every day of the 53 weeks ending on the week of $today (a
     * calendar heatmap), only for days with time.
     *
     * @return array{from: string, to: string, days: array<string, int>}
     */
    public function heatmap(User $user, CarbonImmutable $today, ?int $categoryId = null): array
    {
        $to = $today->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay();
        $from = Day::startOfWeek($today)->subWeeks(52);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $this->secondsByDay($user, $from, $to, $categoryId),
        ];
    }

    /**
     * @return Builder<TimeLog>
     */
    private function logs(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $categoryId = null): Builder
    {
        return TimeLog::query()
            ->where('user_id', $user->id)
            ->whereBetween('log_date', [$from->toDateString(), $to->toDateString()])
            ->when($categoryId !== null, fn (Builder $query) => $query->where('time_category_id', $categoryId));
    }
}
