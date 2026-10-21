<?php

namespace App\Services;

use App\Enums\OccurrenceSource;
use App\Enums\RecurringKind;
use App\Models\ExpenseOccurrence;
use App\Models\Household;
use App\Models\RecurringExpense;
use App\Support\Month;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Materializes recurring expense templates into monthly occurrences.
 *
 * Generation is idempotent: months that already have an occurrence (including
 * soft-deleted ones, which mark a month deliberately removed) are skipped, and
 * the unique (recurring_expense_id, billing_month) index rejects concurrent
 * duplicates.
 */
final class OccurrenceGenerator
{
    public function __construct(private readonly ShareCalculator $shares)
    {
        //
    }

    /**
     * The default month up to which open-ended templates are generated.
     */
    public static function horizon(): CarbonImmutable
    {
        return Month::current()->addMonthsNoOverflow((int) config('finance.generation_months_ahead'));
    }

    /**
     * Generate the missing occurrences of a template. Temporary templates are
     * always generated in full; open-ended ones up to $through.
     *
     * @return int The number of occurrences created.
     */
    public function generate(RecurringExpense $template, ?CarbonImmutable $through = null): int
    {
        $through ??= self::horizon();
        $last = $template->kind === RecurringKind::Temporary ? $template->end_month : $through;

        if ($template->end_month !== null && $last !== null && $last->greaterThan($template->end_month)) {
            $last = $template->end_month;
        }

        if ($last === null || $last->lessThan($template->start_month)) {
            return 0;
        }

        $existing = $template->occurrences()
            ->withTrashed()
            ->pluck('billing_month')
            ->map(fn ($month) => substr((string) $month, 0, 7))
            ->flip();

        $salaries = null;
        $created = 0;

        for ($month = $template->start_month; $month->lessThanOrEqualTo($last); $month = $month->addMonthNoOverflow()) {
            if ($existing->has($month->format('Y-m'))) {
                continue;
            }

            $salaries ??= Household::findOrFail($template->household_id)->salaries()->all();

            try {
                DB::transaction(function () use ($template, $month, $salaries) {
                    $occurrence = ExpenseOccurrence::create([
                        ...self::attributesFor($template, $month),
                        'household_id' => $template->household_id,
                        'scope' => $template->scope,
                        'owner_user_id' => $template->owner_user_id,
                        'source' => OccurrenceSource::Recurring,
                        'recurring_expense_id' => $template->id,
                        'billing_month' => $month,
                        'created_by_user_id' => $template->created_by_user_id,
                    ]);

                    $this->shares->sync($occurrence, $salaries);
                });

                $created++;
            } catch (UniqueConstraintViolationException) {
                // Another process generated this month concurrently.
            }
        }

        return $created;
    }

    /**
     * Generate every template of a household up to the given month.
     */
    public function generateForHousehold(Household $household, ?CarbonImmutable $through = null): int
    {
        $through ??= self::horizon();

        return $household->recurringExpenses()
            ->where('start_month', '<=', $through->toDateString())
            ->get()
            ->sum(fn (RecurringExpense $template) => $this->generate($template, $through));
    }

    /**
     * Generate every template of every household up to the given month.
     */
    public function generateAll(?CarbonImmutable $through = null): int
    {
        return Household::query()->get()->sum(fn (Household $household) => $this->generateForHousehold($household, $through));
    }

    /**
     * The template-derived attributes of the occurrence for a month.
     *
     * @return array<string, mixed>
     */
    public static function attributesFor(RecurringExpense $template, CarbonImmutable $month): array
    {
        return [
            'title' => $template->title,
            'expense_category_id' => $template->expense_category_id,
            'amount_cents' => $template->amount_cents,
            'due_date' => Month::dueDate($month, $template->due_day),
        ];
    }
}
