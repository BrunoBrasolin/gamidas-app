<?php

namespace App\Services;

use App\Enums\ExpenseScope;
use App\Enums\TransactionType;
use App\Models\ExpenseOccurrence;
use App\Models\ExpenseShare;
use App\Models\PersonalTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Computes a member's personal cash flow.
 *
 * Model (accrual by month):
 *   expenses   = personal ledger expenses
 *              + the member's personal bills (expense occurrences they own)
 *              + the member's share of household expenses
 *   net flow   = income − expenses − investments
 *   balance    = cumulative net flow up to the end of the period
 *
 * Investments are a separate outflow, never an expense, so they are not
 * counted twice. The household share is derived automatically from the stored
 * shares, so contributions to the house must not be entered as ledger expenses.
 */
final class PersonalSummary
{
    /**
     * @return array{income_cents: int, ledger_expense_cents: int, personal_bills_cents: int, household_share_cents: int, expense_cents: int, investment_cents: int, net_cents: int, balance_cents: int}
     */
    public function month(User $user, CarbonImmutable $month): array
    {
        $from = $month->startOfMonth();
        $to = $month->endOfMonth();

        return [
            ...$this->flows($user, $from, $to),
            'balance_cents' => $this->balanceAt($user, $to),
        ];
    }

    /**
     * @return array{months: list<array{month: string, income_cents: int, ledger_expense_cents: int, personal_bills_cents: int, household_share_cents: int, expense_cents: int, investment_cents: int, net_cents: int, balance_cents: int}>, totals: array{income_cents: int, ledger_expense_cents: int, personal_bills_cents: int, household_share_cents: int, expense_cents: int, investment_cents: int, net_cents: int}, opening_balance_cents: int, closing_balance_cents: int}
     */
    public function year(User $user, int $year): array
    {
        $from = CarbonImmutable::create($year, 1, 1, 0, 0, 0, config('app.timezone'));
        $to = $from->endOfYear();

        $months = [];

        for ($month = $from; $month->lessThanOrEqualTo($to); $month = $month->addMonthNoOverflow()) {
            $months[$month->format('Y-m')] = $this->emptyFlows();
        }

        $transactions = PersonalTransaction::query()
            ->where('user_id', $user->id)
            ->whereBetween('transaction_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('type, transaction_date, SUM(amount_cents) as aggregate_total')
            ->groupBy('type', 'transaction_date')
            ->get();

        foreach ($transactions as $row) {
            $key = $row->transaction_date->format('Y-m');

            if (isset($months[$key])) {
                $months[$key][$this->transactionKey($row->type)] += (int) $row->getAttribute('aggregate_total');
            }
        }

        $bills = $this->personalBillsQuery($user, $from, $to)
            ->selectRaw('billing_month, SUM(amount_cents) as aggregate_total')
            ->groupBy('billing_month')
            ->get();

        foreach ($bills as $row) {
            $key = $row->billing_month->format('Y-m');

            if (isset($months[$key])) {
                $months[$key]['personal_bills_cents'] += (int) $row->getAttribute('aggregate_total');
            }
        }

        $shares = $this->householdShareQuery($user, $from, $to)
            ->selectRaw('expense_occurrences.billing_month as month, SUM(expense_shares.amount_cents) as aggregate_total')
            ->groupBy('expense_occurrences.billing_month')
            ->get();

        foreach ($shares as $row) {
            $key = substr((string) $row->getAttribute('month'), 0, 7);

            if (isset($months[$key])) {
                $months[$key]['household_share_cents'] += (int) $row->getAttribute('aggregate_total');
            }
        }

        $balance = $this->balanceAt($user, $from->subDay());
        $opening = $balance;
        $rows = [];
        $totals = $this->emptyFlows();

        foreach ($months as $key => $flows) {
            $flows = $this->finalize($flows);
            $balance += $flows['net_cents'];
            $rows[] = ['month' => $key, ...$flows, 'balance_cents' => $balance];

            foreach ($totals as $field => $value) {
                $totals[$field] = $value + $flows[$field];
            }
        }

        return [
            'months' => $rows,
            'totals' => $this->finalize($totals),
            'opening_balance_cents' => $opening,
            'closing_balance_cents' => $balance,
        ];
    }

    /**
     * Cumulative net flow of every movement up to and including the date.
     */
    public function balanceAt(User $user, CarbonImmutable $date): int
    {
        return $this->flows($user, null, $date)['net_cents'];
    }

    /**
     * @return array{income_cents: int, ledger_expense_cents: int, personal_bills_cents: int, household_share_cents: int, expense_cents: int, investment_cents: int, net_cents: int}
     */
    private function flows(User $user, ?CarbonImmutable $from, CarbonImmutable $to): array
    {
        $flows = $this->emptyFlows();

        $transactions = PersonalTransaction::query()
            ->where('user_id', $user->id)
            ->when($from, fn ($query) => $query->where('transaction_date', '>=', $from->toDateString()))
            ->where('transaction_date', '<=', $to->toDateString())
            ->selectRaw('type, SUM(amount_cents) as aggregate_total')
            ->groupBy('type')
            ->get();

        foreach ($transactions as $row) {
            $flows[$this->transactionKey($row->type)] += (int) $row->getAttribute('aggregate_total');
        }

        $flows['personal_bills_cents'] = (int) $this->personalBillsQuery($user, $from, $to)->sum('amount_cents');
        $flows['household_share_cents'] = (int) $this->householdShareQuery($user, $from, $to)->sum('expense_shares.amount_cents');

        return $this->finalize($flows);
    }

    /**
     * @return Builder<ExpenseOccurrence>
     */
    private function personalBillsQuery(User $user, ?CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return ExpenseOccurrence::query()
            ->where('household_id', $user->householdId())
            ->where('scope', ExpenseScope::Personal)
            ->where('owner_user_id', $user->id)
            ->when($from, fn ($query) => $query->where('billing_month', '>=', $from->startOfMonth()->toDateString()))
            ->where('billing_month', '<=', $to->startOfMonth()->toDateString());
    }

    /**
     * @return Builder<ExpenseShare>
     */
    private function householdShareQuery(User $user, ?CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return ExpenseShare::query()
            ->join('expense_occurrences', 'expense_occurrences.id', '=', 'expense_shares.expense_occurrence_id')
            ->where('expense_shares.user_id', $user->id)
            ->where('expense_occurrences.household_id', $user->householdId())
            ->where('expense_occurrences.scope', ExpenseScope::Household->value)
            ->whereNull('expense_occurrences.deleted_at')
            ->when($from, fn ($query) => $query->where('expense_occurrences.billing_month', '>=', $from->startOfMonth()->toDateString()))
            ->where('expense_occurrences.billing_month', '<=', $to->startOfMonth()->toDateString());
    }

    /**
     * @return 'income_cents'|'ledger_expense_cents'|'investment_cents'
     */
    private function transactionKey(TransactionType $type): string
    {
        return match ($type) {
            TransactionType::Income => 'income_cents',
            TransactionType::Expense => 'ledger_expense_cents',
            TransactionType::Investment => 'investment_cents',
        };
    }

    /**
     * @return array{income_cents: int, ledger_expense_cents: int, personal_bills_cents: int, household_share_cents: int, expense_cents: int, investment_cents: int, net_cents: int}
     */
    private function emptyFlows(): array
    {
        return [
            'income_cents' => 0,
            'ledger_expense_cents' => 0,
            'personal_bills_cents' => 0,
            'household_share_cents' => 0,
            'expense_cents' => 0,
            'investment_cents' => 0,
            'net_cents' => 0,
        ];
    }

    /**
     * @param  array{income_cents: int, ledger_expense_cents: int, personal_bills_cents: int, household_share_cents: int, expense_cents: int, investment_cents: int, net_cents: int}  $flows
     * @return array{income_cents: int, ledger_expense_cents: int, personal_bills_cents: int, household_share_cents: int, expense_cents: int, investment_cents: int, net_cents: int}
     */
    private function finalize(array $flows): array
    {
        $flows['expense_cents'] = $flows['ledger_expense_cents'] + $flows['personal_bills_cents'] + $flows['household_share_cents'];
        $flows['net_cents'] = $flows['income_cents'] - $flows['expense_cents'] - $flows['investment_cents'];

        return $flows;
    }
}
