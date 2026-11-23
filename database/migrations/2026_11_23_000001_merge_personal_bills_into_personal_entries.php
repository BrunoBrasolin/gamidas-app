<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Personal bills (expense occurrences with the personal scope, in
     * Finanças pessoais › Contas) are merged into the personal ledger: each
     * one becomes an expense entry of its owner with the same title, notes
     * and amount, dated in the month it counted in, so every personal total
     * stays the same. Bank transactions catalogued as or linked to a bill now
     * point at its entry (as a "Despesa pessoal"), and inbox rules that
     * linked to a personal recurring bill become "Despesa pessoal" rules
     * with its title. The personal bills, their recurring templates and their
     * payment history are then removed.
     *
     * Bills without an amount yet (a variable month still to be filled in)
     * and months already removed by hand carry no money and are dropped.
     */
    public function up(): void
    {
        DB::transaction(function () {
            $now = CarbonImmutable::now();

            $bills = DB::table('expense_occurrences')->where('scope', 'personal')->orderBy('id')->get();

            foreach ($bills as $bill) {
                $entryId = null;

                if ($bill->deleted_at === null && (int) $bill->amount_cents > 0) {
                    $entryId = DB::table('personal_transactions')->insertGetId([
                        'user_id' => $bill->owner_user_id ?? $bill->created_by_user_id,
                        'type' => 'expense',
                        'title' => $bill->title,
                        'amount_cents' => $bill->amount_cents,
                        'transaction_date' => $this->entryDate((string) $bill->billing_month, (string) $bill->due_date),
                        'notes' => $bill->notes,
                        'created_at' => $bill->created_at ?? $now,
                        'updated_at' => $now,
                    ]);
                }

                $this->moveBankLinks((int) $bill->id, $entryId, $now);
            }

            $recurringIds = DB::table('recurring_expenses')->where('scope', 'personal')->pluck('id');

            foreach (DB::table('recurring_expenses')->whereIn('id', $recurringIds)->get(['id', 'title']) as $recurring) {
                DB::table('bank_rules')->where('recurring_expense_id', $recurring->id)->update([
                    'action' => 'personal',
                    'recurring_expense_id' => null,
                    'title' => $recurring->title,
                    'updated_at' => $now,
                ]);
            }

            $billIds = $bills->pluck('id');

            if ($billIds->isNotEmpty()) {
                DB::table('payment_events')->whereIn('expense_occurrence_id', $billIds)->delete();
                DB::table('expense_shares')->whereIn('expense_occurrence_id', $billIds)->delete();
                DB::table('bank_settlement_items')->whereIn('expense_occurrence_id', $billIds)->delete();
                DB::table('expense_occurrences')->whereIn('id', $billIds)->delete();
            }

            // Household months generated from a personal template cannot
            // exist, but never leave one pointing at a removed template.
            DB::table('expense_occurrences')->whereIn('recurring_expense_id', $recurringIds)->update(['recurring_expense_id' => null]);
            DB::table('recurring_expenses')->whereIn('id', $recurringIds)->delete();
        });
    }

    /**
     * Personal bills are not restored.
     */
    public function down(): void
    {
        //
    }

    /**
     * The due date when it falls in the billing month (it always does for
     * bills created by hand), otherwise the first day of the billing month:
     * the entry must count in the same month the bill did.
     */
    private function entryDate(string $billingMonth, string $dueDate): string
    {
        $month = substr($billingMonth, 0, 7);

        return substr($dueDate, 0, 7) === $month ? substr($dueDate, 0, 10) : $month.'-01';
    }

    /**
     * Bank transactions (and parts of split ones) catalogued as or linked to
     * the bill follow it to its entry. Without an entry, a catalogued
     * transaction goes back to the inbox.
     */
    private function moveBankLinks(int $billId, ?int $entryId, CarbonImmutable $now): void
    {
        $transactions = DB::table('bank_transactions')->where('expense_occurrence_id', $billId);

        if ($entryId !== null) {
            $transactions->update([
                'expense_occurrence_id' => null,
                'personal_transaction_id' => $entryId,
                'catalog_type' => 'personal',
                'updated_at' => $now,
            ]);

            DB::table('bank_split_items')->where('expense_occurrence_id', $billId)->update([
                'type' => 'personal',
                'expense_occurrence_id' => null,
                'personal_transaction_id' => $entryId,
                'updated_at' => $now,
            ]);

            return;
        }

        $transactions->update([
            'expense_occurrence_id' => null,
            'review_status' => 'pending',
            'catalog_type' => null,
            'reviewed_at' => null,
            'updated_at' => $now,
        ]);

        DB::table('bank_split_items')->where('expense_occurrence_id', $billId)->update([
            'expense_occurrence_id' => null,
            'updated_at' => $now,
        ]);
    }
};
