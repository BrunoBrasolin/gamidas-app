<?php

namespace Tests\Feature\Finance;

use App\Enums\ExpenseScope;
use App\Models\AuditLog;
use App\Services\PaymentService;
use App\Services\SalaryService;
use App\Support\Month;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class SalaryRecalculationTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createHousehold();
    }

    public function test_household_expenses_are_split_80_20_and_stored(): void
    {
        $expense = $this->createExpense($this->leticia, 100_000);

        $this->assertSame([$this->bruno->id => 80_000, $this->leticia->id => 20_000], $this->sharesOf($expense));
        $this->assertDatabaseHas('expense_shares', [
            'expense_occurrence_id' => $expense->id,
            'user_id' => $this->bruno->id,
            'salary_cents' => 800_000,
            'combined_salary_cents' => 1_000_000,
        ]);
    }

    public function test_shares_add_up_exactly_to_the_expense_amount(): void
    {
        $expense = $this->createExpense($this->bruno, 33_333);

        $this->assertSame(33_333, array_sum($this->sharesOf($expense)));
        $this->assertSame([$this->bruno->id => 26_666, $this->leticia->id => 6_667], $this->sharesOf($expense));
    }

    public function test_personal_expenses_are_not_split(): void
    {
        $expense = $this->createExpense($this->bruno, 15_000, scope: ExpenseScope::Personal);

        $this->assertSame([], $this->sharesOf($expense));
        $this->assertSame($this->bruno->id, $expense->owner_user_id);
    }

    public function test_updating_salaries_recalculates_unpaid_occurrences(): void
    {
        $current = $this->createExpense($this->leticia, 100_000);
        $future = $this->createExpense($this->leticia, 50_000, Month::current()->addMonth());

        $recalculated = app(SalaryService::class)->update($this->household, [
            $this->bruno->id => 600_000,
            $this->leticia->id => 400_000,
        ], $this->bruno);

        $this->assertSame(2, $recalculated);
        $this->assertSame([$this->bruno->id => 60_000, $this->leticia->id => 40_000], $this->sharesOf($current));
        $this->assertSame([$this->bruno->id => 30_000, $this->leticia->id => 20_000], $this->sharesOf($future));
        $this->assertDatabaseHas('household_members', ['user_id' => $this->leticia->id, 'monthly_salary_cents' => 400_000]);
    }

    public function test_updating_salaries_does_not_modify_paid_occurrences(): void
    {
        $paid = $this->createExpense($this->leticia, 100_000);
        app(PaymentService::class)->confirm($paid, $this->bruno);
        $paidAt = $paid->fresh()->paid_at;

        app(SalaryService::class)->update($this->household, [
            $this->bruno->id => 500_000,
            $this->leticia->id => 500_000,
        ], $this->bruno);

        $paid->refresh();
        $this->assertSame([$this->bruno->id => 80_000, $this->leticia->id => 20_000], $this->sharesOf($paid));
        $this->assertTrue($paid->isPaid());
        $this->assertEquals($paidAt, $paid->paid_at);
        $this->assertSame($this->bruno->id, $paid->paid_by_user_id);
        $this->assertDatabaseHas('expense_shares', [
            'expense_occurrence_id' => $paid->id,
            'user_id' => $this->bruno->id,
            'salary_cents' => 800_000,
        ]);
    }

    public function test_salary_changes_are_audited(): void
    {
        $this->createExpense($this->leticia, 100_000);

        app(SalaryService::class)->update($this->household, [$this->bruno->id => 900_000], $this->leticia);

        $log = AuditLog::query()->where('action', 'salaries.updated')->sole();
        $this->assertSame($this->leticia->id, $log->user_id);
        $this->assertSame(800_000, $log->old_values['salaries'][$this->bruno->id]);
        $this->assertSame(900_000, $log->new_values['salaries'][$this->bruno->id]);
        $this->assertSame(1, $log->new_values['recalculated_pending_occurrences']);
    }

    public function test_unchanged_salaries_do_not_recalculate_or_audit(): void
    {
        $this->createExpense($this->leticia, 100_000);

        $recalculated = app(SalaryService::class)->update($this->household, [$this->bruno->id => 800_000], $this->bruno);

        $this->assertSame(0, $recalculated);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'salaries.updated']);
    }

    public function test_zero_combined_salaries_split_equally(): void
    {
        app(SalaryService::class)->update($this->household, [$this->bruno->id => 0, $this->leticia->id => 0], $this->bruno);

        $expense = $this->createExpense($this->bruno, 10_001);

        $this->assertSame([$this->bruno->id => 5_001, $this->leticia->id => 5_000], $this->sharesOf($expense));
    }

    public function test_salaries_page_updates_through_http(): void
    {
        $expense = $this->createExpense($this->leticia, 100_000);

        $this->actingAs($this->bruno)
            ->put(route('household.salaries.update'), [
                'salaries' => [
                    ['user_id' => $this->bruno->id, 'amount' => '7000.00'],
                    ['user_id' => $this->leticia->id, 'amount' => '3000'],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame([$this->bruno->id => 70_000, $this->leticia->id => 30_000], $this->sharesOf($expense));
    }

    public function test_salary_validation_rejects_invalid_values_and_foreign_members(): void
    {
        $outsider = $this->createOutsider();

        $this->actingAs($this->bruno)
            ->put(route('household.salaries.update'), [
                'salaries' => [
                    ['user_id' => $this->bruno->id, 'amount' => '-100'],
                    ['user_id' => $this->leticia->id, 'amount' => '1.000,00'],
                    ['user_id' => $outsider->id, 'amount' => '100.00'],
                ],
            ])
            ->assertSessionHasErrors(['salaries.0.amount', 'salaries.1.amount', 'salaries.2.user_id']);

        $this->actingAs($this->bruno)
            ->put(route('household.salaries.update'), [
                'salaries' => [['user_id' => $this->bruno->id, 'amount' => '1000000.00']],
            ])
            ->assertSessionHasErrors(['salaries.0.amount']);

        $this->assertDatabaseHas('household_members', ['user_id' => $this->bruno->id, 'monthly_salary_cents' => 800_000]);
        $this->assertDatabaseHas('household_members', ['user_id' => $outsider->id, 'monthly_salary_cents' => 300_000]);
    }
}
