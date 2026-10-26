<?php

namespace App\Services;

use App\Enums\ExpenseScope;
use App\Enums\OccurrenceSource;
use App\Enums\OccurrenceStatus;
use App\Enums\RecurringKind;
use App\Models\ExpenseOccurrence;
use App\Models\ExpenseShare;
use App\Models\Household;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Aggregates household expenses for the dashboard and reports.
 *
 * Every figure comes from persisted occurrences and their stored shares.
 * Recurring templates are never summed, so nothing is counted twice.
 */
final class HouseholdSummary
{
    /**
     * Totals of the household's shared expenses for one month.
     *
     * @return array{total_cents: int, paid_cents: int, pending_cents: int, count: int, paid_count: int, pending_count: int, overdue_count: int, awaiting_amount_count: int, by_source: array<string, int>, by_member: array<int, array{user_id: int, total_cents: int, paid_cents: int, pending_cents: int}>}
     */
    public function month(Household $household, CarbonImmutable $month): array
    {
        $base = fn (): Builder => ExpenseOccurrence::query()
            ->household($household->id)
            ->forMonth($month);

        $statusTotals = $base()
            ->selectRaw('status, COUNT(*) as aggregate_count, SUM(amount_cents) as aggregate_total')
            ->groupBy('status')
            ->get()
            ->keyBy(fn ($row) => $row->status->value);

        $paid = $statusTotals->get(OccurrenceStatus::Paid->value);
        $pending = $statusTotals->get(OccurrenceStatus::Pending->value);

        $paidCents = (int) ($paid?->getAttribute('aggregate_total') ?? 0);
        $pendingCents = (int) ($pending?->getAttribute('aggregate_total') ?? 0);
        $paidCount = (int) ($paid?->getAttribute('aggregate_count') ?? 0);
        $pendingCount = (int) ($pending?->getAttribute('aggregate_count') ?? 0);

        $overdueCount = $base()->overdue()->count();
        $awaitingAmountCount = $base()->awaitingAmount()->count();

        return [
            'total_cents' => $paidCents + $pendingCents,
            'paid_cents' => $paidCents,
            'pending_cents' => $pendingCents,
            'count' => $paidCount + $pendingCount,
            'paid_count' => $paidCount,
            'pending_count' => $pendingCount,
            'overdue_count' => $overdueCount,
            'awaiting_amount_count' => $awaitingAmountCount,
            'by_source' => $this->bySource($household, $month),
            'by_member' => $this->byMember($household, $month, $month),
        ];
    }

    /**
     * Month-by-month totals of a year plus yearly totals.
     *
     * @return array{months: list<array{month: string, total_cents: int, paid_cents: int, pending_cents: int, members: array<int, int>}>, total_cents: int, paid_cents: int, pending_cents: int, by_member: array<int, array{user_id: int, total_cents: int, paid_cents: int, pending_cents: int}>}
     */
    public function year(Household $household, int $year): array
    {
        $from = CarbonImmutable::create($year, 1, 1, 0, 0, 0, config('app.timezone'));
        $to = $from->endOfYear()->startOfMonth();

        $rows = ExpenseOccurrence::query()
            ->household($household->id)
            ->whereBetween('billing_month', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('billing_month, status, SUM(amount_cents) as aggregate_total')
            ->groupBy('billing_month', 'status')
            ->get();

        $memberRows = $this->shareQuery($household, $from, $to)
            ->selectRaw('expense_occurrences.billing_month as month, expense_shares.user_id, SUM(expense_shares.amount_cents) as aggregate_total')
            ->groupBy('expense_occurrences.billing_month', 'expense_shares.user_id')
            ->get();

        $months = [];

        for ($month = $from; $month->lessThanOrEqualTo($to); $month = $month->addMonthNoOverflow()) {
            $months[$month->format('Y-m')] = [
                'month' => $month->format('Y-m'),
                'total_cents' => 0,
                'paid_cents' => 0,
                'pending_cents' => 0,
                'members' => [],
            ];
        }

        foreach ($rows as $row) {
            $key = $row->billing_month->format('Y-m');
            $amount = (int) $row->getAttribute('aggregate_total');

            if (! isset($months[$key])) {
                continue;
            }

            $months[$key]['total_cents'] += $amount;

            if ($row->status === OccurrenceStatus::Paid) {
                $months[$key]['paid_cents'] += $amount;
            } else {
                $months[$key]['pending_cents'] += $amount;
            }
        }

        foreach ($memberRows as $row) {
            $key = substr((string) $row->getAttribute('month'), 0, 7);

            if (isset($months[$key])) {
                $months[$key]['members'][(int) $row->getAttribute('user_id')] = (int) $row->getAttribute('aggregate_total');
            }
        }

        $months = array_values($months);

        return [
            'months' => $months,
            'total_cents' => array_sum(array_column($months, 'total_cents')),
            'paid_cents' => array_sum(array_column($months, 'paid_cents')),
            'pending_cents' => array_sum(array_column($months, 'pending_cents')),
            'by_member' => $this->byMember($household, $from, $to),
        ];
    }

    /**
     * Totals split by where the charges come from.
     *
     * @return array<string, int>
     */
    private function bySource(Household $household, CarbonImmutable $month): array
    {
        $totals = ['one_off' => 0, 'fixed' => 0, 'temporary' => 0];

        $rows = ExpenseOccurrence::query()
            ->household($household->id)
            ->forMonth($month)
            ->leftJoin('recurring_expenses', 'recurring_expenses.id', '=', 'expense_occurrences.recurring_expense_id')
            ->selectRaw('expense_occurrences.source, recurring_expenses.kind, SUM(expense_occurrences.amount_cents) as aggregate_total')
            ->groupBy('expense_occurrences.source', 'recurring_expenses.kind')
            ->get();

        foreach ($rows as $row) {
            $key = match ($row->source) {
                OccurrenceSource::OneOff => 'one_off',
                OccurrenceSource::Recurring => $row->getAttribute('kind') === RecurringKind::Temporary->value ? 'temporary' : 'fixed',
            };

            $totals[$key] += (int) $row->getAttribute('aggregate_total');
        }

        return $totals;
    }

    /**
     * Each member's stored shares between two billing months (inclusive).
     *
     * @return array<int, array{user_id: int, total_cents: int, paid_cents: int, pending_cents: int}>
     */
    private function byMember(Household $household, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->shareQuery($household, $from, $to)
            ->selectRaw('expense_shares.user_id, expense_occurrences.status, SUM(expense_shares.amount_cents) as aggregate_total')
            ->groupBy('expense_shares.user_id', 'expense_occurrences.status')
            ->get();

        $members = [];

        foreach ($household->members as $member) {
            $members[$member->user_id] = ['user_id' => $member->user_id, 'total_cents' => 0, 'paid_cents' => 0, 'pending_cents' => 0];
        }

        foreach ($rows as $row) {
            $userId = (int) $row->getAttribute('user_id');
            $amount = (int) $row->getAttribute('aggregate_total');
            $members[$userId] ??= ['user_id' => $userId, 'total_cents' => 0, 'paid_cents' => 0, 'pending_cents' => 0];
            $members[$userId]['total_cents'] += $amount;
            $members[$userId][$row->getAttribute('status') === OccurrenceStatus::Paid->value ? 'paid_cents' : 'pending_cents'] += $amount;
        }

        return array_values($members);
    }

    /**
     * Shares of the household's live occurrences between two billing months.
     *
     * @return Builder<ExpenseShare>
     */
    private function shareQuery(Household $household, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return ExpenseShare::query()
            ->join('expense_occurrences', 'expense_occurrences.id', '=', 'expense_shares.expense_occurrence_id')
            ->where('expense_occurrences.household_id', $household->id)
            ->where('expense_occurrences.scope', ExpenseScope::Household->value)
            ->whereNull('expense_occurrences.deleted_at')
            ->whereBetween('expense_occurrences.billing_month', [$from->toDateString(), $to->toDateString()]);
    }
}
