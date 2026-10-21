<?php

namespace App\Services;

use App\Enums\ExpenseScope;
use App\Enums\OccurrenceStatus;
use App\Enums\RecurringKind;
use App\Models\Household;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Support\Month;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates, edits, ends and deletes recurring expense templates while keeping
 * their generated occurrences consistent.
 *
 * Editing a template only changes pending, non-customized occurrences from an
 * effective month onwards. Paid occurrences and earlier months never change.
 */
final class RecurringExpenseService
{
    public function __construct(
        private readonly OccurrenceGenerator $generator,
        private readonly ShareCalculator $shares,
        private readonly AuditLogger $audit,
    ) {
        //
    }

    /**
     * @param  array{scope: ExpenseScope, kind: RecurringKind, title: string, notes: ?string, expense_category_id: ?int, amount_cents: int, due_day: int, start_month: CarbonImmutable, end_month: ?CarbonImmutable, total_months: ?int}  $data
     */
    public function create(Household $household, User $actor, array $data): RecurringExpense
    {
        return DB::transaction(function () use ($household, $actor, $data) {
            $template = RecurringExpense::create([
                ...$data,
                ...$this->period($data['kind'], $data['start_month'], $data['end_month'], $data['total_months']),
                'household_id' => $household->id,
                'owner_user_id' => $data['scope'] === ExpenseScope::Personal ? $actor->id : null,
                'created_by_user_id' => $actor->id,
            ]);

            $this->generator->generate($template);

            $this->audit->record('recurring_expense.created', $template, $actor, $household->id, null, $this->snapshot($template));

            return $template;
        });
    }

    /**
     * @param  array{title: string, notes: ?string, expense_category_id: ?int, amount_cents: int, due_day: int, end_month: ?CarbonImmutable, total_months: ?int}  $data
     */
    public function update(RecurringExpense $template, User $actor, array $data, CarbonImmutable $effectiveFrom): RecurringExpense
    {
        return DB::transaction(function () use ($template, $actor, $data, $effectiveFrom) {
            $template = RecurringExpense::query()->lockForUpdate()->findOrFail($template->id);
            $old = $this->snapshot($template);

            $period = $this->period($template->kind, $template->start_month, $data['end_month'], $data['total_months']);
            $this->ensureNoPaidAfter($template, $period['end_month']);

            $template->update([
                ...$data,
                ...$period,
                'ended_at' => $period['end_month'] === null ? null : $template->ended_at,
                'updated_by_user_id' => $actor->id,
            ]);

            $this->removePendingAfter($template, $period['end_month']);

            $effectiveFrom = $effectiveFrom->max($template->start_month);

            $affected = $template->occurrences()
                ->where('status', OccurrenceStatus::Pending)
                ->where('is_customized', false)
                ->where('billing_month', '>=', $effectiveFrom->toDateString())
                ->get();

            foreach ($affected as $occurrence) {
                $occurrence->update([
                    ...OccurrenceGenerator::attributesFor($template, $occurrence->billing_month),
                    'updated_by_user_id' => $actor->id,
                ]);

                $this->shares->sync($occurrence);
            }

            $this->generator->generate($template);

            $this->audit->record('recurring_expense.updated', $template, $actor, $template->household_id, $old, [
                ...$this->snapshot($template),
                'effective_from' => Month::key($effectiveFrom),
                'updated_occurrences' => $affected->count(),
            ]);

            return $template;
        });
    }

    /**
     * End a template after the given month: no occurrences are generated past
     * it and pending ones after it are removed. Paid history is kept.
     */
    public function end(RecurringExpense $template, User $actor, CarbonImmutable $lastMonth): RecurringExpense
    {
        return DB::transaction(function () use ($template, $actor, $lastMonth) {
            $template = RecurringExpense::query()->lockForUpdate()->findOrFail($template->id);

            if ($lastMonth->lessThan($template->start_month)) {
                throw ValidationException::withMessages([
                    'end_month' => 'O último mês não pode ser anterior ao mês de início.',
                ]);
            }

            if ($template->end_month !== null && $lastMonth->greaterThan($template->end_month)) {
                throw ValidationException::withMessages([
                    'end_month' => 'Para estender a despesa, edite-a.',
                ]);
            }

            $this->ensureNoPaidAfter($template, $lastMonth);
            $old = $this->snapshot($template);

            $template->update([
                'end_month' => $lastMonth,
                'total_months' => $template->kind === RecurringKind::Temporary
                    ? Month::diff($template->start_month, $lastMonth) + 1
                    : $template->total_months,
                'ended_at' => Date::now(),
                'updated_by_user_id' => $actor->id,
            ]);

            $removed = $this->removePendingAfter($template, $lastMonth);

            $this->audit->record('recurring_expense.ended', $template, $actor, $template->household_id, $old, [
                ...$this->snapshot($template),
                'removed_pending_occurrences' => $removed,
            ]);

            return $template;
        });
    }

    /**
     * Delete a template and its occurrences. Templates with paid occurrences
     * must be ended instead, so that payment history is preserved.
     */
    public function delete(RecurringExpense $template, User $actor): void
    {
        DB::transaction(function () use ($template, $actor) {
            $template = RecurringExpense::query()->lockForUpdate()->findOrFail($template->id);

            if ($template->occurrences()->withTrashed()->where('status', OccurrenceStatus::Paid)->exists()) {
                throw ValidationException::withMessages([
                    'recurring_expense' => 'Esta despesa já tem meses pagos. Encerre-a em vez de excluí-la para preservar o histórico.',
                ]);
            }

            $this->audit->record('recurring_expense.deleted', $template, $actor, $template->household_id, $this->snapshot($template));

            $template->occurrences()->withTrashed()->forceDelete();
            $template->delete();
        });
    }

    /**
     * Normalize the end of a template's period from its kind.
     *
     * @return array{end_month: ?CarbonImmutable, total_months: ?int}
     */
    private function period(RecurringKind $kind, CarbonImmutable $start, ?CarbonImmutable $endMonth, ?int $totalMonths): array
    {
        if ($kind === RecurringKind::Temporary) {
            $totalMonths = max(1, (int) $totalMonths);

            return [
                'end_month' => $start->addMonthsNoOverflow($totalMonths - 1),
                'total_months' => $totalMonths,
            ];
        }

        if ($endMonth !== null && $endMonth->lessThan($start)) {
            throw ValidationException::withMessages([
                'end_month' => 'O mês final não pode ser anterior ao mês de início.',
            ]);
        }

        return ['end_month' => $endMonth, 'total_months' => null];
    }

    private function ensureNoPaidAfter(RecurringExpense $template, ?CarbonImmutable $lastMonth): void
    {
        if ($lastMonth === null) {
            return;
        }

        $paidAfter = $template->occurrences()
            ->where('status', OccurrenceStatus::Paid)
            ->where('billing_month', '>', $lastMonth->toDateString())
            ->exists();

        if ($paidAfter) {
            throw ValidationException::withMessages([
                'end_month' => 'Existem meses pagos depois do novo término. Reabra-os antes de encurtar a despesa.',
            ]);
        }
    }

    /**
     * Remove pending occurrences generated past the template's new end.
     * They never existed in the new plan, so their slots are freed entirely.
     */
    private function removePendingAfter(RecurringExpense $template, ?CarbonImmutable $lastMonth): int
    {
        if ($lastMonth === null) {
            return 0;
        }

        return $template->occurrences()
            ->withTrashed()
            ->where('status', OccurrenceStatus::Pending)
            ->where('billing_month', '>', $lastMonth->toDateString())
            ->forceDelete();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(RecurringExpense $template): array
    {
        return [
            'title' => $template->title,
            'kind' => $template->kind->value,
            'scope' => $template->scope->value,
            'amount_cents' => $template->amount_cents,
            'due_day' => $template->due_day,
            'expense_category_id' => $template->expense_category_id,
            'start_month' => Month::key($template->start_month),
            'end_month' => $template->end_month ? Month::key($template->end_month) : null,
            'total_months' => $template->total_months,
        ];
    }
}
