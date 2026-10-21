<?php

namespace Tests\Feature\Finance;

use App\Enums\ExpenseScope;
use App\Enums\RecurringKind;
use App\Services\ExpenseService;
use App\Services\PaymentService;
use App\Services\RecurringExpenseService;
use App\Support\Month;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesHousehold;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use CreatesHousehold, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Date::setTestNow('2026-10-21 10:00:00');
        $this->createHousehold();
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    private function seedMonth(): void
    {
        app(RecurringExpenseService::class)->create($this->household, $this->bruno, [
            'scope' => ExpenseScope::Household, 'kind' => RecurringKind::Fixed, 'title' => 'Internet', 'notes' => null,
            'amount_cents' => 10_000, 'due_day' => 25,
            'start_month' => Month::parse('2026-10'), 'end_month' => null, 'total_months' => null,
        ]);
        app(RecurringExpenseService::class)->create($this->household, $this->leticia, [
            'scope' => ExpenseScope::Household, 'kind' => RecurringKind::Temporary, 'title' => 'Sofá', 'notes' => null,
            'amount_cents' => 30_000, 'due_day' => 5,
            'start_month' => Month::parse('2026-10'), 'end_month' => null, 'total_months' => 4,
        ]);
        app(ExpenseService::class)->create($this->household, $this->leticia, [
            'scope' => ExpenseScope::Household, 'title' => 'Notebook', 'notes' => null,
            'amount_cents' => 20_000, 'due_date' => CarbonImmutable::parse('2026-10-15'),
        ], 6);
        $paid = $this->createExpense($this->leticia, 50_000, Month::parse('2026-10'));
        app(PaymentService::class)->confirm($paid, $this->bruno);
        $this->createExpense($this->bruno, 7_700, Month::parse('2026-10'), ExpenseScope::Personal);
    }

    public function test_the_dashboard_totals_come_from_persisted_occurrences(): void
    {
        $this->seedMonth();

        $this->actingAs($this->bruno)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('month', '2026-10')
                ->where('combinedSalaryCents', 1_000_000)
                ->where('proportions.'.$this->bruno->id, 8_000)
                ->where('proportions.'.$this->leticia->id, 2_000)
                // 100 (internet) + 300 (extra) + 200 (notebook installment) + 500 (one-off); personal bills excluded.
                ->where('household.total_cents', 110_000)
                ->where('household.paid_cents', 50_000)
                ->where('household.pending_cents', 60_000)
                ->where('household.by_source.fixed', 10_000)
                ->where('household.by_source.temporary', 30_000)
                ->where('household.by_source.one_off', 70_000)
                ->where('household.by_member.0.total_cents', 88_000)
                ->where('household.by_member.1.total_cents', 22_000)
                ->where('personal.personal_bills_cents', 7_700)
                ->where('personal.household_share_cents', 88_000)
                // Pending and due by 05/11: two overdue (extra and installment in October),
                // Internet on 25/10, Bruno's personal bill and November's extra on 05/11.
                ->has('upcoming', 5)
                ->where('upcoming.0.due_date', '2026-10-05')
                ->where('upcoming.4.due_date', '2026-11-05')
            );
    }

    public function test_navigating_to_another_month(): void
    {
        $this->seedMonth();

        $this->actingAs($this->bruno)
            ->get(route('dashboard', ['month' => '2027-01']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('month', '2027-01')
                // Internet is generated on demand for January; the extra is in its last month.
                ->where('household.by_source.fixed', 10_000)
                ->where('household.by_source.temporary', 30_000)
                ->where('household.by_source.one_off', 20_000)
                ->where('household.total_cents', 60_000)
            );

        $this->actingAs($this->bruno)
            ->get(route('dashboard', ['month' => '2027-02']))
            ->assertInertia(fn (Assert $page) => $page->where('household.by_source.temporary', 0));
    }

    public function test_invalid_month_parameters_fall_back_to_the_current_month(): void
    {
        $this->actingAs($this->bruno)
            ->get(route('dashboard', ['month' => '2026-13']))
            ->assertInertia(fn (Assert $page) => $page->where('month', '2026-10'));
    }

    public function test_the_current_month_follows_the_sao_paulo_timezone(): void
    {
        // 31 Oct 23:30 in São Paulo is already 1 Nov in UTC.
        Date::setTestNow(CarbonImmutable::parse('2026-11-01 02:30:00', 'UTC'));

        $this->actingAs($this->bruno)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('month', '2026-10'));
    }

    public function test_zero_salaries_are_reported_without_dividing_by_zero(): void
    {
        $this->household->members()->update(['monthly_salary_cents' => 0]);
        $this->createExpense($this->bruno, 10_001);

        $this->actingAs($this->leticia)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('combinedSalaryCents', 0)
                ->where('proportions.'.$this->bruno->id, 5_000)
                ->where('proportions.'.$this->leticia->id, 5_000)
            );
    }
}
